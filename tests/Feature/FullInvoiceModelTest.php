<?php

namespace Tests\Feature;

use App\Enums\RectificationReason;
use App\Enums\TaxIdType;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Exceptions\ImmutableTicketException;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FullInvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(array $attributes = []): Ticket
    {
        $settings = Setting::current();
        $series = $settings->defaultTicketSeries;

        return Ticket::create($attributes + [
            'ticket_series_id' => $series->id, 'series_code' => $series->code, 'number' => Ticket::count() + 1,
            'type' => TicketType::Simplified, 'status' => TicketStatus::Issued, 'issued_by' => User::factory()->create()->id,
            'issued_at' => now(), 'subtotal' => 1000, 'tax_base' => 909, 'tax_amount' => 91, 'total' => 1000,
            'vat_breakdown' => [['rate' => 1000, 'base' => 909, 'tax' => 91, 'total' => 1000]],
            'issuer' => $settings->issuerSnapshot(), 'payment_status' => 'pagado',
        ]);
    }

    public function test_series_f_and_fr_exist_and_are_separate(): void
    {
        $settings = $this->createSettings()->refresh();

        $this->assertNotSame($settings->defaultTicketSeries->id, $settings->invoiceSeries->id);
        $this->assertFalse($settings->invoiceSeries->is_rectifying);
        $this->assertTrue($settings->invoiceRectifyingSeries->is_rectifying);
        $this->assertNotSame($settings->rectifyingTicketSeries->id, $settings->invoiceRectifyingSeries->id);
        $this->assertTrue($settings->invoice_require_address);
    }

    public function test_ticket_can_go_from_issued_to_exchanged_only_once(): void
    {
        $this->createSettings();
        $ticket = $this->ticket();

        $ticket->update(['status' => TicketStatus::Exchanged]);
        $this->assertSame(TicketStatus::Exchanged, $ticket->refresh()->status);

        $this->expectException(ImmutableTicketException::class);
        $ticket->update(['status' => TicketStatus::Rectified]);
    }

    public function test_database_trigger_allows_exchanged_but_not_back(): void
    {
        $this->createSettings();
        $ticket = $this->ticket();

        DB::table('tickets')->where('id', $ticket->id)->update(['status' => 'canjeado']);
        $this->expectException(QueryException::class);
        DB::table('tickets')->where('id', $ticket->id)->update(['status' => 'emitido']);
    }

    public function test_a_ticket_can_be_replaced_by_one_invoice_only(): void
    {
        $this->createSettings();
        $ticket = $this->ticket();
        $settings = Setting::current();
        $invoice = fn () => $this->ticket(['type' => TicketType::InvoiceExchange, 'ticket_series_id' => $settings->invoice_series_id,
            'series_code' => 'F', 'replaces_ticket_id' => $ticket->id, 'recipient' => ['name' => 'Empresa SL']]);

        $first = $invoice();
        $this->assertTrue($first->replacedTicket->is($ticket));
        $this->assertTrue($ticket->exchangedBy->is($first));

        $this->expectException(QueryException::class); // índice único
        $invoice();
    }

    public function test_verifactu_types(): void
    {
        $this->assertSame('F2', TicketType::Simplified->verifactuType());
        $this->assertSame('R5', TicketType::Rectifying->verifactuType());
        $this->assertSame('F1', TicketType::Invoice->verifactuType());
        $this->assertSame('F3', TicketType::InvoiceExchange->verifactuType());
        $this->assertSame('R1', TicketType::InvoiceRectifying->verifactuType(RectificationReason::ErrorOrDiscount));
        $this->assertSame('R2', TicketType::InvoiceRectifying->verifactuType(RectificationReason::Insolvency));
        $this->assertSame('R3', TicketType::InvoiceRectifying->verifactuType(RectificationReason::BadDebt));
        $this->assertSame('R4', TicketType::InvoiceRectifying->verifactuType(RectificationReason::Other));
        $this->assertSame(TicketType::InvoiceRectifying, TicketType::InvoiceExchange->rectifyingType());
        $this->assertSame(TicketType::Rectifying, TicketType::Simplified->rectifyingType());
    }

    public function test_customer_fiscal_data_and_snapshot(): void
    {
        $customer = Customer::create(['legal_name' => 'Restaurants del Port SL', 'tax_id' => ' b-12345674 ', 'tax_id_type' => TaxIdType::Cif,
            'fiscal_address' => 'Passeig dels Guíxols, 5', 'fiscal_postal_code' => '17220', 'fiscal_city' => 'Sant Feliu de Guíxols', 'fiscal_country' => 'ES']);

        $this->assertSame('B12345674', $customer->tax_id);
        $this->assertTrue($customer->hasFiscalData());
        $this->assertSame('Restaurants del Port SL', $customer->recipientSnapshot()['name']);
        $this->assertFalse($customer->acceptsEmailInvoices());
        $this->assertFalse(Customer::create(['legal_name' => 'Sin domicilio', 'tax_id' => '12345678Z'])->hasFiscalData());
        $this->assertTrue(Customer::create(['legal_name' => 'Sin domicilio 2', 'tax_id' => '00000000T'])->hasFiscalData(requireAddress: false));
    }
}
