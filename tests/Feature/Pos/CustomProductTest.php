<?php

namespace Tests\Feature\Pos;

use App\Enums\PrintJobKind;
use App\Models\OrderLine;
use App\Models\PrintJob;
use App\Models\Ticket;

/**
 * Producto personalizado (fuera de la carta): nombre y precio escritos en la
 * caja («Varios»…). Va a la impresora de su categoría, con el IVA habitual de
 * sus productos, y sale en la comanda y en el ticket como cualquier otro.
 */
class CustomProductTest extends PosTestCase
{
    public function test_custom_line_with_name_price_vat_and_category_printer(): void
    {
        $this->product('Agua', 150, $this->drinks, ['vat_rate' => 1000]);
        $this->product('Vino', 400, $this->drinks, ['vat_rate' => 2100]);
        $this->product('Cerveza', 250, $this->drinks, ['vat_rate' => 2100]);
        $order = $this->openTable();

        $this->asWaiter()->postJson("/api/orders/{$order['id']}/custom-lines", ['category_id' => $this->drinks->id, 'name' => 'Varios', 'price' => 350])
            ->assertCreated()->assertJsonPath('data.total', 350);

        $line = OrderLine::query()->sole();
        $this->assertNull($line->product_id);
        $this->assertSame('Varios', $line->name);
        $this->assertSame(350, $line->unit_price);
        $this->assertSame(2100, $line->vat_rate, 'El IVA más habitual de la categoría.');
        $this->assertSame($this->drinks->printer_id, $line->printer_id);

        // Comanda y ticket, como cualquier línea.
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/send")->assertOk();
        $this->assertStringContainsString('Varios', $this->printedText(PrintJob::query()->where('kind', PrintJobKind::Kitchen)->sole()->content));
        $this->openCash();
        $this->checkout($order['id'], [['method' => 'efectivo', 'amount' => 350, 'tendered' => 500]])->assertCreated();
        $this->assertSame(['Varios'], Ticket::query()->sole()->lines()->pluck('name')->all());
    }

    public function test_needs_a_name_and_a_valid_price(): void
    {
        $order = $this->openTable();
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/custom-lines", ['category_id' => $this->drinks->id, 'name' => '', 'price' => 350])->assertUnprocessable();
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/custom-lines", ['category_id' => $this->drinks->id, 'name' => 'Varios', 'price' => -1])->assertUnprocessable();
        $this->assertSame(0, OrderLine::query()->count());
    }
}
