<?php

namespace Tests\Feature\Pos;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PrintJobKind;
use App\Enums\TableStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Table;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Pos\ReceiptFormatter;

/** Unir mesas, separarlas y liberar una mesa abierta por error. */
class JoinTablesTest extends PosTestCase
{
    private Room $room;

    private Product $tapa;

    private Product $beer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->room = Room::factory()->create(['name' => 'Sala']);
        $this->tapa = $this->product('Bravas', 600);
        $this->beer = $this->product('Caña', 250, $this->drinks);
    }

    private function table(string $name, array $attributes = []): Table
    {
        return Table::factory()->create(['name' => $name, 'room_id' => $this->room->id] + $attributes);
    }

    /** Abre la mesa con productos ($send: enviarlos a cocina). */
    private function open(Table $table, array $products = [], int $guests = 2, bool $send = false): array
    {
        $order = $this->openTable($table, $guests);
        foreach ($products as [$product, $quantity]) {
            $this->addLine($order['id'], ['product_id' => $product->id, 'quantity' => $quantity])->assertCreated();
        }
        if ($send) {
            $this->asWaiter()->postJson("/api/orders/{$order['id']}/send")->assertOk();
        }

        return $this->asWaiter()->getJson("/api/orders/{$order['id']}")->json('data');
    }

    private function join(int $orderId, array $tables, array $accounts = [])
    {
        return $this->asWaiter()->postJson("/api/orders/{$orderId}/join", [
            'table_ids' => array_map(fn (Table $t) => $t->id, $tables), 'accounts' => $accounts,
        ]);
    }

    private function floorTable(Table $table): array
    {
        return collect($this->asWaiter()->getJson('/api/rooms')->json('data'))->flatMap(fn ($r) => $r['tables'])->firstWhere('id', $table->id);
    }

    public function test_join_a_free_table(): void
    {
        [$t3, $t4] = [$this->table('3'), $this->table('4')];
        $order = $this->open($t3, [[$this->tapa, 1]]);

        $data = $this->join($order['id'], [$t4])->assertOk()->json('data');

        $this->assertSame('Mesa 3 + 4', $data['table_label']);
        $this->assertSame([['id' => $t3->id, 'name' => '3', 'is_main' => true], ['id' => $t4->id, 'name' => '4', 'is_main' => false]], $data['tables']);
        $this->assertSame(TableStatus::Occupied, $t4->fresh()->status);
        $link = OrderTable::query()->where('table_id', $t4->id)->sole();
        $this->assertSame($this->waiter->id, $link->joined_by);
        $this->assertNotNull($link->joined_at);

        // En el plano: mismo grupo y etiqueta en las dos, total en la principal; tocar la 4 abre el pedido del grupo.
        $main = $this->floorTable($t3);
        $joined = $this->floorTable($t4);
        $this->assertSame('3+4', $main['group']['label']);
        $this->assertSame('3+4', $joined['group']['label']);
        $this->assertTrue($main['group']['is_main']);
        $this->assertSame(600, $main['total']);
        $this->assertSame(0, $joined['total']);
        $this->assertSame($order['id'], $joined['order']['id']);
    }

    public function test_join_an_occupied_table_moves_its_lines_without_reprinting(): void
    {
        [$t3, $t4] = [$this->table('3'), $this->table('4')];
        $order = $this->open($t3, [[$this->tapa, 1]], guests: 2, send: true);
        $other = $this->open($t4, [[$this->beer, 2], [$this->tapa, 1]], guests: 3, send: true);
        $this->addLine($other['id'], ['product_id' => $this->beer->id, 'quantity' => 1])->assertCreated();   // pendiente
        $line = Order::query()->findOrFail($other['id'])->lines()->where('product_id', $this->tapa->id)->firstOrFail();
        app(OrderService::class)->inviteLine($line, $this->waiter, 'Cliente habitual');
        $jobs = PrintJob::query()->count();

        $data = $this->join($order['id'], [$t4])->assertOk()->assertJsonPath('merged', [$other['id']])->json('data');

        $this->assertSame(PrintJob::query()->count(), $jobs, 'Lo enviado no se reimprime.');
        $this->assertSame(5, $data['guests']);
        $this->assertCount(4, $data['lines']);
        $this->assertSame(1, $data['pending_count']);
        $this->assertSame(600 + 3 * 250, $data['total']);   // la invitación sigue
        $this->assertTrue(collect($data['lines'])->firstWhere('is_invitation', true)['line_total'] > 0);
        $this->assertSame([0 => 1, 1 => 2], collect($data['sends'])->pluck('number')->all());
        $this->assertNotEmpty($data['vat_breakdown']);
        $this->assertSame(array_sum(array_column($data['vat_breakdown'], 'total')), $data['total']);

        $merged = Order::query()->findOrFail($other['id']);
        $this->assertSame(OrderStatus::Merged, $merged->status);
        $this->assertSame($order['id'], $merged->merged_into_order_id);
        $this->assertSame($this->waiter->id, $merged->merged_by);
        $this->assertFalse($merged->tickets()->exists());
        $this->assertSame(0, $merged->lines()->count());
    }

    public function test_join_several_tables_at_once(): void
    {
        [$t3, $t4, $t5] = [$this->table('3'), $this->table('4'), $this->table('5')];
        $order = $this->open($t3, [[$this->tapa, 1]]);
        $this->open($t5, [[$this->beer, 1]]);

        $data = $this->join($order['id'], [$t4, $t5])->assertOk()->json('data');

        $this->assertSame('Mesa 3 + 4 + 5', $data['table_label']);
        $this->assertSame(850, $data['total']);
        $this->assertSame(4, $data['guests']);
    }

    public function test_table_with_several_accounts_asks_which_and_joins_only_with_all(): void
    {
        [$t3, $t4] = [$this->table('3'), $this->table('4')];
        $order = $this->open($t3, [[$this->tapa, 1]]);
        $first = $this->open($t4, [[$this->beer, 2], [$this->tapa, 1]]);
        $beerLine = collect($first['lines'])->firstWhere('name', 'Caña')['id'];
        $second = $this->asWaiter()->postJson("/api/orders/{$first['id']}/split", ['lines' => [$beerLine => 2]])->assertCreated()->json('data');

        $this->join($order['id'], [$t4])->assertUnprocessable()->assertJsonPath('message', 'La mesa 4 tiene 2 cuentas: elige cuál unir.');

        $data = $this->join($order['id'], [$t4], [$t4->id => [$second['id']]])->assertOk()->json('data');
        $this->assertSame('Mesa 3', $data['table_label'], 'Le queda otra cuenta: no entra en el grupo.');
        $this->assertSame(1100, $data['total']);
        $this->assertSame(TableStatus::Occupied, $t4->fresh()->status);
        $this->assertSame([$first['id']], $t4->openOrders()->pluck('id')->all());

        $data = $this->join($order['id'], [$t4], [$t4->id => 'all'])->assertOk()->json('data');
        $this->assertSame('Mesa 3 + 4', $data['table_label']);
    }

    public function test_rejects_invalid_tables(): void
    {
        [$t3, $t4, $t5, $t6] = [$this->table('3'), $this->table('4'), $this->table('5'), $this->table('6')];
        $inactive = $this->table('9', ['is_active' => false]);
        $order = $this->open($t3);
        $other = $this->open($t5, [[$this->beer, 1]]);
        $this->join($other['id'], [$t6])->assertOk();

        $this->join($order['id'], [$inactive])->assertConflict()->assertJsonPath('message', 'La mesa 9 está fuera de servicio.');
        $this->join($order['id'], [$t6])->assertConflict()->assertJsonPath('message', 'La mesa 6 ya está unida a la mesa 5.');
        $this->join($order['id'], [$t5])->assertConflict()->assertJsonPath('message', 'La mesa 5 tiene mesas unidas: sepáralas antes de unirla.');
        $this->join($order['id'], [$t3])->assertUnprocessable();

        $discounted = $this->open($t4, [[$this->tapa, 2]]);
        app(OrderService::class)->discountOrder(Order::query()->findOrFail($discounted['id']), DiscountType::Percentage, 1000, $this->waiter);
        $this->join($order['id'], [$t4])->assertUnprocessable()
            ->assertJsonPath('message', 'La cuenta de la mesa 4 tiene un descuento global: quítalo antes de unirla (mesa 4 → Cobrar → Descuento al pedido → Quitar descuento).');
        $this->assertSame(OrderStatus::Open, Order::query()->find($discounted['id'])->status);

        // «Quitar descuento» (valor 0) y ya se puede unir.
        $this->asWaiter()->postJson("/api/orders/{$discounted['id']}/discount", ['type' => 'porcentaje', 'value' => 0])->assertOk();
        $this->join($order['id'], [$t4])->assertOk();
    }

    public function test_separate_frees_the_table_and_keeps_the_lines(): void
    {
        [$t3, $t4] = [$this->table('3'), $this->table('4')];
        $order = $this->open($t3, [[$this->tapa, 1]]);
        $this->open($t4, [[$this->beer, 1]]);
        $this->join($order['id'], [$t4])->assertOk();

        $this->asWaiter()->postJson("/api/orders/{$order['id']}/separate", ['table_ids' => [$t3->id]])->assertUnprocessable();
        $data = $this->asWaiter()->postJson("/api/orders/{$order['id']}/separate", ['table_ids' => [$t4->id]])->assertOk()->json('data');

        $this->assertSame('Mesa 3', $data['table_label']);
        $this->assertSame(850, $data['total']);
        $this->assertSame(TableStatus::Free, $t4->fresh()->status);
        $link = OrderTable::query()->where('table_id', $t4->id)->where('is_main', false)->sole();
        $this->assertSame($this->waiter->id, $link->left_by);
        $this->assertNotNull($link->left_at);
        // Se puede volver a abrir.
        $this->open($t4);
    }

    public function test_checkout_frees_the_whole_group_and_documents_show_both_tables(): void
    {
        $this->openCash();
        [$t3, $t4, $t5] = [$this->table('3'), $this->table('4'), $this->table('5')];
        $order = $this->open($t3, [[$this->tapa, 2], [$this->beer, 1]]);
        $this->join($order['id'], [$t4, $t5])->assertOk();
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/precheck")->assertOk();
        $this->assertSame(TableStatus::BillRequested, $t5->fresh()->status);
        $precheck = PrintJob::query()->where('kind', PrintJobKind::Precheck)->latest('id')->firstOrFail();
        $this->assertStringContainsString('Mesa 3 + 4 + 5', $this->printedText($precheck->content));

        // D3: una cuenta separada del grupo se queda en la mesa principal.
        $beerLine = Order::query()->findOrFail($order['id'])->lines()->where('product_id', $this->beer->id)->value('id');
        $split = $this->asWaiter()->postJson("/api/orders/{$order['id']}/split", ['lines' => [$beerLine => 1]])->assertCreated()->json('data');
        $this->assertSame('Mesa 3', $split['table_label']);
        $this->assertSame([['id' => $split['id'], 'total' => 250]], $this->asWaiter()->getJson("/api/orders/{$order['id']}")->json('data.other_accounts'));

        $ticketId = $this->checkout($order['id'], [['method' => 'efectivo', 'amount' => 1200]])->assertCreated()->json('data.ticket.id');

        $this->assertSame(TableStatus::Free, $t4->fresh()->status);
        $this->assertSame(TableStatus::Free, $t5->fresh()->status);
        $this->assertNotSame(TableStatus::Free, $t3->fresh()->status, 'Sigue con la cuenta separada.');
        $ticket = Ticket::query()->findOrFail($ticketId);
        $this->assertSame('Mesa 3 + 4 + 5', $ticket->table_label);
        $this->assertStringContainsString('Mesa 3 + 4 + 5', $this->printedText(app(ReceiptFormatter::class)->ticket($ticket)->operations()));
        $this->assertSame(0, OrderTable::query()->whereNull('left_at')->where('order_id', $order['id'])->count());
        $this->assertSame('Mesa 3 + 4 + 5', Order::query()->findOrFail($order['id'])->tableLabel());

        $this->checkout($split['id'], [['method' => 'efectivo', 'amount' => 250]])->assertCreated();
        $this->assertSame(TableStatus::Free, $t3->fresh()->status);
    }

    public function test_joining_an_account_with_precheck_puts_the_group_back_to_occupied(): void
    {
        [$t3, $t4] = [$this->table('3'), $this->table('4')];
        $order = $this->open($t3, [[$this->tapa, 1]]);
        $other = $this->open($t4, [[$this->beer, 1]]);
        $this->asWaiter()->postJson("/api/orders/{$other['id']}/precheck")->assertOk();
        $floor = $this->floorTable($t4);
        $this->assertTrue($floor['order']['precheck_printed']);

        $this->join($order['id'], [$t4])->assertOk();

        $this->assertSame(TableStatus::Occupied, $t3->fresh()->status);
        $this->assertSame(TableStatus::Occupied, $t4->fresh()->status);
        $this->assertSame(1, Order::query()->findOrFail($other['id'])->prechecks()->count(), 'La precuenta se conserva.');
    }

    public function test_move_moves_only_the_main_table(): void
    {
        [$t3, $t4, $t7] = [$this->table('3'), $this->table('4'), $this->table('7')];
        $order = $this->open($t3, [[$this->tapa, 1]]);
        $this->join($order['id'], [$t4])->assertOk();

        $data = $this->asWaiter()->postJson("/api/orders/{$order['id']}/move", ['table_id' => $t7->id])->assertOk()->json('data');

        $this->assertSame('Mesa 7 + 4', $data['table_label']);
        $this->assertSame(TableStatus::Free, $t3->fresh()->status);
        $this->assertSame(TableStatus::Occupied, $t4->fresh()->status);
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/move", ['table_id' => $t4->id])->assertConflict();
    }

    public function test_optional_join_notice_for_kitchen_and_bar(): void
    {
        [$t3, $t4, $t5] = [$this->table('3'), $this->table('4'), $this->table('5')];
        $order = $this->open($t3);
        $this->join($order['id'], [$t4])->assertOk();
        $this->assertSame(0, PrintJob::query()->where('kind', PrintJobKind::JoinNotice)->count(), 'Desactivado por defecto.');

        Setting::current()->update(['print_join_notice' => true]);
        $this->join($order['id'], [$t5])->assertOk();

        $jobs = PrintJob::query()->where('kind', PrintJobKind::JoinNotice)->get();
        $this->assertEqualsCanonicalizing([$this->kitchen->id, $this->bar->id], $jobs->pluck('printer_id')->all());
        $this->assertStringContainsString('MESA 5 UNIDA A MESA 3', $this->printedText($jobs->first()->content));
    }

    public function test_kitchen_ticket_after_joining_shows_the_group(): void
    {
        [$t3, $t4] = [$this->table('3'), $this->table('4')];
        $order = $this->open($t3);
        $this->join($order['id'], [$t4])->assertOk();
        $this->addLine($order['id'], ['product_id' => $this->tapa->id])->assertCreated();
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/send")->assertOk();

        $job = PrintJob::query()->where('kind', PrintJobKind::Kitchen)->latest('id')->firstOrFail();
        $this->assertStringContainsString('MESA 3 + 4', $this->printedText($job->content));
    }

    public function test_release_an_empty_table(): void
    {
        $t3 = $this->table('3');
        $order = $this->open($t3);
        $this->assertNull($order['release_blocker']);

        $this->asWaiter()->postJson("/api/orders/{$order['id']}/release")->assertOk()->assertJsonPath('discarded', 0);

        $cancelled = Order::query()->findOrFail($order['id']);
        $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
        $this->assertSame('Abierta por error', $cancelled->cancel_reason);
        $this->assertSame($this->waiter->id, $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(TableStatus::Free, $t3->fresh()->status);
    }

    public function test_release_discards_pending_lines_but_keeps_them_in_the_cancelled_order(): void
    {
        $order = $this->open($t3 = $this->table('3'), [[$this->tapa, 2], [$this->beer, 1]]);

        $this->asWaiter()->postJson("/api/orders/{$order['id']}/release")->assertOk()->assertJsonPath('discarded', 2);

        $this->assertSame(2, Order::query()->findOrFail($order['id'])->lines()->count());
        $this->assertSame(TableStatus::Free, $t3->fresh()->status);
    }

    public function test_release_is_rejected_after_sending_or_printing_the_bill(): void
    {
        $sent = $this->open($this->table('3'), [[$this->tapa, 1]], send: true);
        $this->assertSame('Ya se envió a cocina: anula las líneas o cobra la mesa.', $sent['release_blocker']);
        $this->asWaiter()->postJson("/api/orders/{$sent['id']}/release")->assertUnprocessable()
            ->assertJsonPath('message', 'Ya se envió a cocina: anula las líneas o cobra la mesa.');

        $printed = $this->open($this->table('4'));
        $this->asWaiter()->postJson("/api/orders/{$printed['id']}/precheck")->assertOk();
        $this->asWaiter()->postJson("/api/orders/{$printed['id']}/release")->assertUnprocessable()
            ->assertJsonPath('message', 'Ya se imprimió la cuenta: cobra la mesa o anula las líneas.');
        $this->assertSame(OrderStatus::Open, Order::query()->find($printed['id'])->status);
    }

    public function test_release_a_joined_group_frees_every_table(): void
    {
        [$t3, $t4, $t5] = [$this->table('3'), $this->table('4'), $this->table('5')];
        $order = $this->open($t3);
        $this->open($t5, [[$this->beer, 1]]);
        $this->join($order['id'], [$t4, $t5])->assertOk();

        $this->asWaiter()->postJson("/api/orders/{$order['id']}/release")->assertOk()->assertJsonPath('discarded', 1);

        foreach ([$t3, $t4, $t5] as $table) {
            $this->assertSame(TableStatus::Free, $table->fresh()->status);
        }
    }

    public function test_admin_shows_who_joined_and_separated(): void
    {
        [$t3, $t4, $t5] = [$this->table('3'), $this->table('4'), $this->table('5')];
        $order = $this->open($t3, [[$this->tapa, 1]]);
        $other = $this->open($t5, [[$this->beer, 1]]);
        $this->join($order['id'], [$t4, $t5])->assertOk();
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/separate", ['table_ids' => [$t4->id]])->assertOk();

        $admin = User::factory()->role(UserRole::Admin)->create();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'web')->get("/admin/pedidos/{$order['id']}")->assertOk()
            ->assertSee('Mesa 4: unida el')->assertSee('separada el')->assertSee('por Ana López')
            ->assertSee("Pedido #{$other['id']} de la mesa 5 unido el");
        $this->get("/admin/pedidos/{$other['id']}")->assertOk()->assertSee('Unido a otra mesa')->assertSee("#{$order['id']}");
    }
}
