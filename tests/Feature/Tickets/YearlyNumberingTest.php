<?php

namespace Tests\Feature\Tickets;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketSeries;
use App\Services\Billing\InvoiceDocument;
use App\Services\Billing\InvoiceExchanger;
use App\Services\Cash\CashSessionService;
use App\Services\Pos\CheckoutService;
use App\Services\Tickets\RectificationService;
use App\Services\Tickets\TicketIssuer;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Tests\Feature\Pos\InvoiceTestCase;

/** Numeración por año natural (art. 6.1.a RD 1619/2012): T/R/F/FR-AA-NNNNNN y Z-AA-NNNN. */
class YearlyNumberingTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Los códigos reales del local (la factory pone códigos aleatorios).
        Setting::current()->defaultTicketSeries->update(['code' => 'T']);
        Setting::current()->rectifyingTicketSeries->update(['code' => 'R']);
    }

    private function madrid(string $datetime): Carbon
    {
        return Carbon::parse($datetime, 'Europe/Madrid');
    }

    private function issue(?Customer $recipient = null): Ticket
    {
        $order = Order::query()->findOrFail($this->orderWith([[$this->pizza, 1]]));

        return app(TicketIssuer::class)->issue($order, $this->waiter, CheckoutService::openSession(), recipient: $recipient);
    }

    private function rectify(Ticket $ticket): Ticket
    {
        return app(RectificationService::class)->byDifferences($ticket, $this->waiter, 'Error', refund: false);
    }

    public function test_every_series_restarts_on_new_year_in_madrid_time_even_with_the_cash_opened_last_year(): void
    {
        $this->travelTo($this->madrid('2026-12-31 20:00:00'));
        $session = $this->openCash();

        $this->travelTo($this->madrid('2026-12-31 23:59:59'));
        $t1 = $this->issue();
        $f1 = $this->issue($this->company);
        $r1 = $this->rectify($t1);
        $fr1 = $this->rectify($f1);
        $this->assertSame(['T-26-000001', 'F-26-000001', 'R-26-000001', 'FR-26-000001'], [$t1->fullNumber(), $f1->fullNumber(), $r1->fullNumber(), $fr1->fullNumber()]);
        $this->assertSame(2026, $t1->year);

        // Un segundo después, en la misma caja (abierta en 2026): todas vuelven a 1.
        $this->travelTo($this->madrid('2027-01-01 00:00:00'));
        $t2 = $this->issue();
        $f2 = $this->issue($this->company);
        $r2 = $this->rectify($t2);
        $fr2 = $this->rectify($f2);
        $this->assertSame(['T-27-000001', 'F-27-000001', 'R-27-000001', 'FR-27-000001'], [$t2->fullNumber(), $f2->fullNumber(), $r2->fullNumber(), $fr2->fullNumber()]);
        $this->assertSame($session->id, $t2->cash_session_id);
        $this->assertSame([1, 1], [$t1->number, $t2->number]);

        $this->assertSame('T-27-000002', $this->issue()->fullNumber());
    }

    public function test_rectifying_and_exchange_of_last_year_documents_take_this_year_number(): void
    {
        $this->travelTo($this->madrid('2026-12-31 22:00:00'));
        $this->openCash();
        $order = $this->orderWith([[$this->pizza, 1]]);
        $this->checkout($order, [['method' => 'efectivo', 'amount' => 1000, 'tendered' => 1000]])->assertCreated();
        $ticket = Ticket::query()->where('order_id', $order)->sole();

        $this->travelTo($this->madrid('2027-01-02 10:00:00'));
        $invoice = app(InvoiceExchanger::class)->exchange($ticket, $this->company, $this->waiter, confirmOtherYear: true);
        // FAQ 27: el ticket canjeado se rectifica (R5) y el cliente recibe la F3 del rectificativo.
        $rect = $this->rectify($ticket->refresh());
        $exchangeOfRect = Ticket::query()->where('replaces_ticket_id', $rect->id)->sole();

        $this->assertSame('T-26-000001', $ticket->fullNumber());
        $this->assertSame('F-27-000001', $invoice->fullNumber());
        $this->assertStringContainsString('Sustituye a la factura simplificada T-26-000001', InvoiceDocument::make($invoice)['replaces']);
        $this->assertSame('R-27-000001', $rect->fullNumber());
        $this->assertSame('F-27-000002', $exchangeOfRect->fullNumber());
        $this->assertSame('T-26-000001', $rect->rectifiedTicket->fullNumber());
    }

    public function test_year_is_the_madrid_calendar_year_of_the_instant(): void
    {
        // 31/12 23:30 UTC = 01/01 00:30 en Madrid: año nuevo.
        $this->assertSame(2027, DocumentNumber::yearOf(Carbon::parse('2026-12-31 23:30:00', 'UTC')));
        $this->assertSame(2026, DocumentNumber::yearOf(Carbon::parse('2026-12-31 22:59:59', 'UTC')));
        $this->assertSame(2027, DocumentNumber::yearOf($this->madrid('2027-01-01 00:00:00')));
    }

    public function test_z_restarts_every_year(): void
    {
        $cash = app(CashSessionService::class);

        $this->travelTo($this->madrid('2026-12-31 23:00:00'));
        $a = $cash->close($this->openCash(), $this->waiter, 10000);
        $this->travelTo($this->madrid('2026-12-31 23:59:59'));
        $b = $cash->close($this->openCash(), $this->waiter, 10000);
        $this->travelTo($this->madrid('2027-01-01 00:00:00'));
        $c = $cash->close($this->openCash(), $this->waiter, 10000);

        $this->assertSame(['Z-26-0001', 'Z-26-0002', 'Z-27-0001'], [$a->z_full_number, $b->z_full_number, $c->z_full_number]);
        $this->assertSame('Z-27-0001', $c->z_report['z_full_number']);
    }

    public function test_issued_number_does_not_change_when_the_format_changes(): void
    {
        $this->openCash();
        $ticket = $this->issue();
        $before = $ticket->fullNumber();

        TicketSeries::query()->where('code', 'T')->update(['digits' => 8]);
        $next = $this->issue();

        $this->assertSame($before, $ticket->refresh()->fullNumber());
        $this->assertMatchesRegularExpression('/^T-\d{2}-000001$/', $ticket->full_number);
        $this->assertMatchesRegularExpression('/^T-\d{2}-00000002$/', $next->fullNumber());
    }

    public function test_cash_ticket_search_by_full_number_or_digits_current_year_first(): void
    {
        $this->travelTo($this->madrid('2026-06-01 12:00:00'));
        $this->openCash();
        $old = $this->issue();                       // T-26-000001
        $this->travelTo($this->madrid('2027-03-01 12:00:00'));
        $new = $this->issue();                       // T-27-000001

        $byDigits = $this->asWaiter()->getJson('/api/tickets?q=1')->json('data.*.number');
        $this->assertSame(['T-27-000001', 'T-26-000001'], array_values(array_filter($byDigits, fn ($n) => str_starts_with($n, 'T-'))));
        $this->assertSame(['T-26-000001'], $this->asWaiter()->getJson('/api/tickets?q=T-26-000001')->json('data.*.number'));
        $this->assertSame(['T-26-000001'], $this->asWaiter()->getJson('/api/tickets?q=26-1')->json('data.*.number'));
        $this->assertSame(['T-27-000001', 'T-26-000001'], $this->asWaiter()->getJson('/api/tickets?q=t-1')->json('data.*.number'));
        $this->assertSame([$new->id, $old->id], Ticket::query()->searchNumber('T-1')->pluck('id')->all());
    }
}
