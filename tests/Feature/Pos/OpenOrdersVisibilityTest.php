<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Room;
use App\Models\Table;
use App\Models\User;
use App\Support\FloorLayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ningún pedido abierto puede quedarse sin acceso desde la caja: mesas creadas
 * en /admin que se dibujaban unas encima de otras, ventas rápidas sin cobrar,
 * salas desactivadas… y el cierre Z lleva a cada pedido pendiente.
 */
class OpenOrdersVisibilityTest extends PosTestCase
{
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->room = Room::factory()->create(['name' => 'Comedor']);
    }

    private function floor(): array
    {
        return $this->asWaiter()->getJson('/api/rooms')->assertOk()->json();
    }

    public function test_tables_created_without_position_get_a_free_spot(): void
    {
        // Como en /admin: sin posición (0,0).
        $tables = collect(['1', '2', '3', '4', '5'])->map(fn ($n) => Table::create(['room_id' => $this->room->id, 'name' => $n]));

        $spots = $tables->map(fn (Table $t) => "{$t->pos_x},{$t->pos_y}");
        $this->assertSame(5, $spots->unique()->count(), 'Ninguna mesa encima de otra.');
        $this->assertSame(['0,0', '3,0', '6,0', '9,0', '0,3'], $spots->all());
    }

    public function test_existing_overlapping_tables_are_untangled(): void
    {
        foreach (['1', '2', '3'] as $n) {   // ya guardadas en (0,0), sin pasar por el modelo
            DB::table('tables')->insert(['room_id' => $this->room->id, 'name' => $n, 'pos_x' => 0, 'pos_y' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame(2, FloorLayout::untangle());

        $spots = Table::query()->orderBy('id')->get()->map(fn (Table $t) => "{$t->pos_x},{$t->pos_y}")->all();
        $this->assertSame(['0,0', '3,0', '6,0'], $spots, 'La más antigua se queda; las demás, a huecos libres.');
        $this->assertSame(0, FloorLayout::untangle());
    }

    public function test_abandoned_quick_sale_shows_up_on_the_floor_and_is_reused(): void
    {
        $first = $this->asWaiter()->postJson('/api/quick-sale')->assertCreated()->json('data.id');
        $this->assertSame($first, $this->asWaiter()->postJson('/api/quick-sale')->assertOk()->json('data.id'), 'Venta vacía: se reutiliza.');

        $floor = $this->floor();
        $this->assertSame([$first], array_column($floor['unplaced'], 'id'));
        $this->assertSame('Venta rápida', $floor['unplaced'][0]['label']);
        $this->assertSame('sin mesa', $floor['unplaced'][0]['reason']);

        // Con productos ya no se reutiliza: «Venta rápida» abre otra.
        $this->addLine($first, ['product_id' => $this->product('Caña', 250, $this->drinks)->id])->assertCreated();
        $this->assertNotSame($first, $this->asWaiter()->postJson('/api/quick-sale')->assertCreated()->json('data.id'));
    }

    public function test_quick_sale_without_anything_sent_can_be_discarded(): void
    {
        $id = $this->asWaiter()->postJson('/api/quick-sale')->json('data.id');
        $this->assertNull($this->asWaiter()->getJson("/api/orders/{$id}")->json('data.release_blocker'));

        $this->asWaiter()->postJson("/api/orders/{$id}/release")->assertOk();

        $this->assertSame(OrderStatus::Cancelled, Order::query()->find($id)->status);
        $this->assertSame([], $this->floor()['unplaced']);
    }

    public function test_open_table_in_a_deactivated_room_is_not_lost(): void
    {
        $table = Table::create(['room_id' => $this->room->id, 'name' => '7']);
        $order = $this->openTable($table);
        DB::table('rooms')->where('id', $this->room->id)->update(['is_active' => false]);   // p. ej. desactivada antes de este arreglo

        $unplaced = $this->floor()['unplaced'];
        $this->assertSame([$order['id']], array_column($unplaced, 'id'));
        $this->assertSame('sala Comedor desactivada', $unplaced[0]['reason']);
    }

    public function test_a_room_with_open_tables_cannot_be_deactivated(): void
    {
        $table = Table::create(['room_id' => $this->room->id, 'name' => '7']);
        $this->openTable($table);

        $this->expectException(ValidationException::class);
        $this->room->update(['is_active' => false]);
    }

    public function test_z_lists_each_open_order_with_where_to_resolve_it(): void
    {
        $this->openCash(10000);
        $manager = User::factory()->role(UserRole::Manager)->withPin('9999')->create();
        $table = Table::create(['room_id' => $this->room->id, 'name' => '7']);
        $tableOrder = $this->openTable($table);
        $quick = $this->asWaiter()->postJson('/api/quick-sale')->json('data.id');

        $response = $this->asPos($manager)->postJson('/api/cash-session/close', ['counted_cash' => 10000])->assertStatus(409);

        $this->assertSame([
            ['label' => 'Mesa 7 · 0,00 €', 'path' => "/mesas/pedido/{$tableOrder['id']}"],
            ['label' => 'Venta rápida · 0,00 €', 'path' => "/mesas/pedido/{$quick}"],
        ], $response->json('items'));
    }
}
