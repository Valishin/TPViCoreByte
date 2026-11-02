<?php

namespace Tests\Feature\Pos;

use App\Events\Pos\CashChanged;
use App\Events\Pos\FloorChanged;
use App\Events\Pos\OrderUpdated;
use App\Events\Pos\TableStatusChanged;
use App\Models\Room;
use App\Models\Table;
use Illuminate\Support\Facades\Event;

/**
 * Tiempo real entre aparatos (móvil ↔ caja): cada cambio avisa a las demás
 * pantallas por el canal `pos`, sin recargar.
 */
class RealtimeEventsTest extends PosTestCase
{
    public function test_opening_a_table_tells_the_other_screens(): void
    {
        Event::fake([TableStatusChanged::class, OrderUpdated::class]);
        $table = Table::factory()->create();

        $this->openTable($table);

        Event::assertDispatched(TableStatusChanged::class, fn ($e) => $e->tableId === $table->id && $e->status === 'ocupada');
        Event::assertDispatched(OrderUpdated::class, fn ($e) => $e->tableId === $table->id);
    }

    public function test_a_new_quick_sale_shows_up_on_the_other_screens(): void
    {
        Event::fake([OrderUpdated::class]);

        $id = $this->asWaiter()->postJson('/api/quick-sale')->assertCreated()->json('data.id');
        Event::assertDispatchedTimes(OrderUpdated::class, 1);
        Event::assertDispatched(OrderUpdated::class, fn ($e) => $e->orderId === $id);

        $this->asWaiter()->postJson('/api/quick-sale')->assertOk();   // reutilizada: nada nuevo que avisar
        Event::assertDispatchedTimes(OrderUpdated::class, 1);
    }

    public function test_charging_tells_the_cash_screens(): void
    {
        $this->openCash();
        $order = $this->openTable(Table::factory()->create());
        $this->addLine($order['id'], ['product_id' => $this->product('Caña', 250, $this->drinks)->id])->assertCreated();
        Event::fake([CashChanged::class]);

        $this->asWaiter()->postJson("/api/orders/{$order['id']}/checkout", [
            'idempotency_key' => (string) str()->uuid(), 'payments' => [['method' => 'efectivo', 'amount' => 250, 'tendered' => 500]],
        ])->assertCreated();

        // Ticket, pago y movimiento se agrupan en un aviso por petición (PosNotifier).
        Event::assertDispatched(CashChanged::class);
    }

    public function test_rooms_and_tables_changed_in_admin_refresh_the_floor(): void
    {
        Event::fake([FloorChanged::class, TableStatusChanged::class]);
        $scheduled = fn () => app()->bound('pos.notify.floor');

        app()->forgetInstance('pos.notify.floor');
        $room = Room::factory()->create(['name' => 'Terraza']);
        $table = Table::create(['room_id' => $room->id, 'name' => '20']);
        $table->update(['name' => '21']);
        $this->assertTrue($scheduled(), 'Sala o mesa cambiada: aviso al plano al final de la petición.');
        app()->terminate();
        Event::assertDispatched(FloorChanged::class);

        // Solo el estado (abrir o cerrar mesa): ya lo avisa table.status, sin recargar el plano entero.
        app()->forgetInstance('pos.notify.floor');
        $table->update(['status' => 'ocupada']);
        $this->assertFalse($scheduled());
        Event::assertDispatched(TableStatusChanged::class);
    }
}
