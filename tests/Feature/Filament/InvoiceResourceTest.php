<?php

namespace Tests\Feature\Filament;

use App\Enums\OrderType;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Ticket;
use App\Services\Cash\CashSessionService;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Pos\CheckoutService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

class InvoiceResourceTest extends FilamentTestCase
{
    private Customer $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Customer::create([
            'legal_name' => 'Restaurants del Port SL', 'tax_id' => 'B12345674',
            'fiscal_address' => 'Passeig dels Guíxols, 5', 'fiscal_postal_code' => '17220', 'fiscal_city' => 'Sant Feliu de Guíxols',
        ]);
        app(CashSessionService::class)->open($this->admin, 10000);
    }

    private function issue(?Customer $recipient = null): Ticket
    {
        $order = app(OrderService::class)->open(OrderType::Bar, $this->admin);
        app(OrderLineBuilder::class)->addProduct($order, Product::factory()->price(350)->create(['name' => 'Vermut']), quantity: 2);

        // Cobrado (con tarjeta): solo un ticket cobrado se puede canjear.
        return app(CheckoutService::class)->checkout($order->refresh(), $this->admin, [['method' => 'tarjeta', 'amount' => 700]], uniqid(), print: false, recipient: $recipient)['ticket'];
    }

    public function test_lists_only_full_invoices_read_only_with_filters(): void
    {
        $ticket = $this->issue();
        $invoice = $this->issue($this->company);

        $this->assertFalse(InvoiceResource::canCreate());
        $this->assertFalse(InvoiceResource::hasPage('edit'));

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords([$invoice])
            ->assertCanNotSeeTableRecords([$ticket])
            ->assertSee('Restaurants del Port SL')
            ->assertSee('B12345674')
            ->assertActionDoesNotExist(TestAction::make('edit')->table($invoice))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($invoice))
            ->filterTable('type', TicketType::InvoiceExchange->value)
            ->assertCanNotSeeTableRecords([$invoice])
            ->resetTableFilters()
            ->filterTable('email', 'none')
            ->assertCanSeeTableRecords([$invoice])
            ->searchTable('B1234')
            ->assertCanSeeTableRecords([$invoice]);
    }

    public function test_pdf_download_from_the_list(): void
    {
        $invoice = $this->issue($this->company);

        Livewire::test(ListInvoices::class)
            ->callAction(TestAction::make('pdf')->table($invoice))
            ->assertFileDownloaded('Factura-'.$invoice->fullNumber().'.pdf');
    }

    public function test_exchange_from_the_ticket_page(): void
    {
        $ticket = $this->issue();

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->callAction('exchange', ['customer_id' => $this->company->id])
            ->assertHasNoActionErrors();

        $invoice = Ticket::query()->where('replaces_ticket_id', $ticket->id)->sole();
        $this->assertSame(TicketType::InvoiceExchange, $invoice->type);
        $this->assertSame(TicketStatus::Exchanged, $ticket->refresh()->status);

        // Canjeado: no se vuelve a canjear ni a anular por error; se rectifica solo por diferencias (FAQ 27).
        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->assertActionHidden('exchange')
            ->assertActionHidden('annul')
            ->assertActionHidden('substitute')
            ->assertActionVisible('voidTicket');
    }

    public function test_rectify_full_invoice_with_reason_code(): void
    {
        $invoice = $this->issue($this->company);

        Livewire::test(ViewTicket::class, ['record' => $invoice->id])
            ->assertActionVisible('voidTicket')
            ->assertActionVisible('correctRecipient')
            ->assertActionHidden('exchange')
            ->callAction('voidTicket', ['reason' => 'Descuento posterior', 'reason_code' => 'error_descuento', 'refund_method' => 'tarjeta'])
            ->assertHasNoActionErrors();

        $rect = Ticket::query()->where('rectified_ticket_id', $invoice->id)->sole();
        $this->assertSame(TicketType::InvoiceRectifying, $rect->type);
        $this->assertSame('FR', $rect->series_code);
        $this->assertSame('R1', $rect->verifactuType());
    }
}
