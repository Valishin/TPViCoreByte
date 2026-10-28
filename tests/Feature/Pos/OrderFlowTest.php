<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PrintJobKind;
use App\Enums\PrintJobStatus;
use App\Enums\TableStatus;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\OrderLine;
use App\Models\PrintJob;
use App\Models\Table;
use Illuminate\Support\Arr;

class OrderFlowTest extends PosTestCase
{
    public function test_open_table_and_conflict_when_already_open(): void
    {
        $table = Table::factory()->create();

        $order = $this->openTable($table, guests: 4);
        $this->assertSame(4, $order['guests']);
        $this->assertSame(TableStatus::Occupied, $table->fresh()->status);

        $this->asWaiter()->postJson("/api/tables/{$table->id}/open", ['guests' => 2])
            ->assertStatus(409)->assertJsonPath('message', "La mesa {$table->name} ya está abierta.");
    }

    public function test_rooms_show_table_summary(): void
    {
        $table = Table::factory()->create();
        $order = $this->openTable($table);
        $this->addLine($order['id'], ['product_id' => $this->product('Caña', 220, $this->drinks)->id, 'quantity' => 3])->assertCreated();

        $rooms = $this->asWaiter()->getJson('/api/rooms')->assertOk()->json('data');
        $summary = collect($rooms)->flatMap(fn ($r) => $r['tables'])->firstWhere('id', $table->id);

        $this->assertSame('ocupada', $summary['status']);
        $this->assertSame(660, $summary['order']['total']);
        $this->assertSame('AL', $summary['order']['waiter']['initials']);
    }

    public function test_identical_pending_lines_are_grouped(): void
    {
        $order = $this->openTable();
        $burger = $this->product('Hamburguesa', 1200);
        $group = ModifierGroup::factory()->create();
        $burger->modifierGroups()->attach($group);
        $cheese = Modifier::factory()->for($group, 'group')->create(['name' => 'Queso', 'price_delta' => 100]);

        $this->addLine($order['id'], ['product_id' => $burger->id, 'modifier_ids' => [$cheese->id]]);
        $this->addLine($order['id'], ['product_id' => $burger->id, 'modifier_ids' => [$cheese->id], 'quantity' => 2]);
        $this->addLine($order['id'], ['product_id' => $burger->id]);                                   // sin queso: otra línea
        $this->addLine($order['id'], ['product_id' => $burger->id, 'notes' => 'Sin sal']);            // con nota: otra línea

        $lines = OrderLine::query()->where('order_id', $order['id'])->orderBy('id')->get();
        $this->assertSame([3, 1, 1], $lines->pluck('quantity')->all());

        // Una vez enviada, ya no se agrupa.
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/send")->assertOk();
        $this->addLine($order['id'], ['product_id' => $burger->id, 'modifier_ids' => [$cheese->id]]);
        $this->assertSame(4, OrderLine::query()->where('order_id', $order['id'])->count());
    }

    public function test_menus_and_half_and_half_are_never_grouped(): void
    {
        $order = $this->openTable();
        $menu = $this->menu($this->product('Ensalada', 600), $this->product('Agua', 150, $this->drinks));
        $choices = $menu->menuGroups->map(fn ($g) => ['item_id' => $g->items->first()->id])->all();

        $this->addLine($order['id'], ['product_id' => $menu->id, 'menu_choices' => $choices])->assertCreated();
        $this->addLine($order['id'], ['product_id' => $menu->id, 'menu_choices' => $choices])->assertCreated();

        $this->assertSame(2, OrderLine::query()->where('order_id', $order['id'])->whereNull('parent_line_id')->count());
    }

    public function test_line_validations(): void
    {
        $order = $this->openTable();

        $steak = $this->product('Entrecot', 1950);
        $steak->modifierGroups()->attach(ModifierGroup::factory()->create(['name' => 'Punto', 'min_select' => 1, 'max_select' => 1]));
        $this->addLine($order['id'], ['product_id' => $steak->id])->assertUnprocessable()->assertJsonPath('message', 'Entrecot: elige Punto.');

        $soldOut = $this->product('Pulpo', 1650, attributes: ['is_available' => false]);
        $this->addLine($order['id'], ['product_id' => $soldOut->id])->assertUnprocessable()->assertJsonPath('message', 'Pulpo no está disponible.');

        $menu = $this->menu($this->product('Sopa', 500), $this->product('Vino', 300, $this->drinks));
        $this->addLine($order['id'], ['product_id' => $menu->id, 'menu_choices' => [['item_id' => $menu->menuGroups[0]->items[0]->id]]])
            ->assertUnprocessable();

        $a = $this->pizza('Barbacoa', 1000, 1500);
        $notAllowed = $this->product('Calzone', 1100);
        $this->addLine($order['id'], ['product_id' => $a->id, 'variant_id' => $a->variants[0]->id, 'half' => ['product_id' => $notAllowed->id]])
            ->assertUnprocessable()->assertJsonPath('message', 'Calzone no admite mitad y mitad.');

        $this->assertSame(0, OrderLine::query()->where('order_id', $order['id'])->count());
    }

    public function test_send_routes_each_line_to_its_printer_including_menus_and_halves(): void
    {
        $order = $this->openTable();
        $beer = $this->product('Cerveza', 250, $this->drinks);
        $tapa = $this->product('Bravas', 550);
        $pizzaA = $this->pizza('Barbacoa', 1000, 1500);
        $pizzaB = $this->pizza('Carbonara', 1000, 1500);
        $menu = $this->menu($this->product('Lasaña', 900), $beer);

        $this->addLine($order['id'], ['product_id' => $beer->id, 'quantity' => 2]);
        $this->addLine($order['id'], ['product_id' => $tapa->id, 'notes' => 'Poco picante']);
        $this->addLine($order['id'], ['product_id' => $pizzaA->id, 'variant_id' => $pizzaA->variants->firstWhere('code', 'familiar')->id, 'half' => ['product_id' => $pizzaB->id]]);
        $this->addLine($order['id'], ['product_id' => $menu->id, 'menu_choices' => $menu->menuGroups->map(fn ($g) => ['item_id' => $g->items[0]->id])->all()]);

        $sent = $this->asWaiter()->postJson("/api/orders/{$order['id']}/send")->assertOk()->json('sent');

        $this->assertSame(1, $sent['send_number']);
        $this->assertEqualsCanonicalizing(['Cocina' => 4, 'Barra' => 2], collect($sent['jobs'])->pluck('lines', 'printer')->all());

        $kitchen = PrintJob::query()->where('printer_id', $this->kitchen->id)->firstOrFail();
        $bar = PrintJob::query()->where('printer_id', $this->bar->id)->firstOrFail();
        $this->assertSame(PrintJobStatus::Printed, $kitchen->status); // cola sync + impresora simulada
        $kitchenText = collect($kitchen->content)->pluck('text')->filter()->implode("\n");
        $barText = collect($bar->content)->pluck('text')->filter()->implode("\n");

        $this->assertStringContainsString('MESA 5', $kitchenText);
        $this->assertStringContainsString('1x ½ Barbacoa', $kitchenText);
        $this->assertStringContainsString('mitad y mitad con Carbonara', $kitchenText);
        $this->assertStringContainsString('1x Lasaña', $kitchenText);
        $this->assertStringContainsString('↳ del Menú del día', $kitchenText);
        $this->assertStringContainsString('!! Poco picante', $kitchenText);
        $this->assertStringContainsString('2x Cerveza', $barText);
        $this->assertStringContainsString('↳ del Menú del día', $barText);
        $this->assertStringNotContainsString('Bravas', $barText);

        $this->assertSame(0, OrderLine::query()->where('order_id', $order['id'])->whereNull('sent_at')->count());

        // Segundo envío: nº 2 y solo lo nuevo.
        $this->addLine($order['id'], ['product_id' => $beer->id]);
        $second = $this->asWaiter()->postJson("/api/orders/{$order['id']}/send")->json('sent');
        $this->assertSame(2, $second['send_number']);
        $this->assertSame([['printer' => 'Barra', 'lines' => 1]], collect($second['jobs'])->map(fn ($j) => Arr::only($j, ['printer', 'lines']))->all());
    }

    public function test_void_unsent_line_needs_no_reason_and_prints_nothing(): void
    {
        $order = $this->openTable();
        $line = $this->addLine($order['id'], ['product_id' => $this->product('Bravas', 550)->id])->json('line_id');

        $this->asWaiter()->postJson("/api/order-lines/{$line}/void")->assertOk()->assertJsonPath('data.total', 0);
        $this->assertSame(0, PrintJob::count());
    }

    public function test_void_sent_line_requires_reason_and_prints_cancellation(): void
    {
        $order = $this->openTable();
        $line = $this->addLine($order['id'], ['product_id' => $this->product('Bravas', 550)->id, 'quantity' => 2])->json('line_id');
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/send");

        $this->asWaiter()->postJson("/api/order-lines/{$line}/void")->assertUnprocessable();
        $this->asWaiter()->patchJson("/api/order-lines/{$line}", ['quantity' => 1])->assertUnprocessable();

        $this->asWaiter()->postJson("/api/order-lines/{$line}/void", ['reason' => 'Cliente cambia de idea'])->assertOk();

        $void = PrintJob::query()->where('kind', PrintJobKind::Void)->firstOrFail();
        $this->assertSame($this->kitchen->id, $void->printer_id);
        $text = collect($void->content)->pluck('text')->filter()->implode("\n");
        $this->assertStringContainsString('*** ANULACIÓN ***', $text);
        $this->assertStringContainsString('NO HACER: 2x Bravas', $text);
        $this->assertStringContainsString('Motivo: Cliente cambia de idea', $text);
    }

    public function test_update_unsent_line_quantity_and_notes(): void
    {
        $order = $this->openTable();
        $line = $this->addLine($order['id'], ['product_id' => $this->product('Bravas', 550)->id])->json('line_id');

        $this->asWaiter()->patchJson("/api/order-lines/{$line}", ['quantity' => 3, 'notes' => 'Para compartir'])
            ->assertOk()->assertJsonPath('data.total', 1650)->assertJsonPath('data.lines.0.notes', 'Para compartir');
    }

    public function test_move_to_free_table(): void
    {
        $from = Table::factory()->create();
        $to = Table::factory()->create();
        $busy = Table::factory()->create();
        $order = $this->openTable($from);
        $this->openTable($busy);

        $this->asWaiter()->postJson("/api/orders/{$order['id']}/move", ['table_id' => $busy->id])->assertStatus(409);
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/move", ['table_id' => $to->id])->assertOk()->assertJsonPath('data.table.id', $to->id);

        $this->assertSame(TableStatus::Free, $from->fresh()->status);
        $this->assertSame(TableStatus::Occupied, $to->fresh()->status);
    }

    public function test_precheck_prints_bill_and_marks_table(): void
    {
        $table = Table::factory()->create();
        $order = $this->openTable($table);
        $this->addLine($order['id'], ['product_id' => $this->product('Bravas', 550)->id]);

        $this->asWaiter()->postJson("/api/orders/{$order['id']}/precheck")->assertOk();

        $this->assertSame(TableStatus::BillRequested, $table->fresh()->status);
        $job = PrintJob::query()->where('kind', PrintJobKind::Precheck)->firstOrFail();
        $this->assertSame($this->tickets->id, $job->printer_id);
        $this->assertStringContainsString('NO VÁLIDO COMO FACTURA', collect($job->content)->pluck('text')->implode("\n"));

        // Si piden algo más y se envía, la mesa vuelve a ocupada.
        $this->addLine($order['id'], ['product_id' => $this->product('Café', 140, $this->drinks)->id]);
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/send");
        $this->assertSame(TableStatus::Occupied, $table->fresh()->status);
    }

    public function test_changing_order_type_recalculates_vat(): void
    {
        $table = Table::factory()->create();
        $order = $this->openTable($table);
        $beer = $this->product('Cerveza', 300, $this->drinks, ['vat_rate_takeaway' => 2100]);
        $line = $this->addLine($order['id'], ['product_id' => $beer->id])->json('line_id');
        $this->assertSame(1000, OrderLine::find($line)->vat_rate);

        $this->asWaiter()->patchJson("/api/orders/{$order['id']}/type", ['type' => OrderType::Takeaway->value])
            ->assertOk()->assertJsonPath('data.type', 'para_llevar')->assertJsonPath('data.table', null);

        $this->assertSame(2100, OrderLine::find($line)->vat_rate);
        $this->assertSame(TableStatus::Free, $table->fresh()->status);

        $other = Table::factory()->create();
        $this->asWaiter()->patchJson("/api/orders/{$order['id']}/type", ['type' => OrderType::Table->value, 'table_id' => $other->id])->assertOk();
        $this->assertSame(1000, OrderLine::find($line)->vat_rate);
        $this->assertSame(TableStatus::Occupied, $other->fresh()->status);
    }
}
