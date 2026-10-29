<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cash\CashReportService;
use App\Services\Tickets\RectificationService;

class CashReportTest extends PosTestCase
{
    public function test_z_report_squares_with_tickets_and_payments(): void
    {
        $session = $this->openCash(15000);
        $manager = User::factory()->role(UserRole::Manager)->withPin('9999')->create(['name' => 'Elena']);
        $beer = $this->product('Caña', 250, $this->drinks, ['vat_rate' => 1000]);
        $gin = $this->product('Gin-tonic', 850, $this->drinks, ['vat_rate' => 2100]);

        $a = $this->checkout($this->orderWith([[$beer, 4]]), [['method' => 'efectivo', 'amount' => 1000, 'tendered' => 2000]])->json('data.ticket.id');
        $this->checkout($this->orderWith([[$gin, 2]]), [['method' => 'tarjeta', 'amount' => 1700, 'tip' => 300]]);
        $this->checkout($this->orderWith([[$beer, 2], [$gin, 1]]), [['method' => 'bizum', 'amount' => 600], ['method' => 'efectivo', 'amount' => 750]]);
        app(RectificationService::class)->byDifferences(Ticket::find($a), $manager, 'Error', null);

        $report = app(CashReportService::class)->build($session);

        $this->assertSame(3, $report['tickets']['count']);
        $this->assertSame(1000 + 1700 + 1350, $report['tickets']['total']);
        $this->assertSame(1, $report['rectifications']['count']);
        $this->assertSame(-1000, $report['rectifications']['total']);
        $this->assertSame(3050, $report['sales_total']);
        $this->assertSame((int) Ticket::sum('total'), $report['sales_total']);

        $byMethod = collect($report['by_method'])->pluck('amount', 'method')->all();
        $this->assertSame(['efectivo' => 1000 + 750 - 1000, 'tarjeta' => 1700, 'bizum' => 600], $byMethod);
        $this->assertSame((int) Payment::sum('amount'), array_sum($byMethod));
        $this->assertSame($report['sales_total'], array_sum($byMethod)); // todo cobrado

        // Propinas aparte, por método y camarero; no suman a las ventas.
        $this->assertSame(300, $report['tips']['total']);
        $this->assertSame([['method' => 'tarjeta', 'label' => 'Tarjeta', 'amount' => 300]], $report['tips']['by_method']);
        $this->assertSame('Ana López', $report['tips']['by_waiter'][0]['name']);

        // IVA: cuadra con la suma de los desgloses de todos los tickets.
        $this->assertSame($report['sales_total'], array_sum(array_column($report['by_vat'], 'total')));
        $this->assertEqualsCanonicalizing([1000, 2100], array_column($report['by_vat'], 'rate'));

        $this->assertSame(15000 + 750, $report['cash']['expected']);
    }

    public function test_close_with_open_tables_needs_explicit_confirmation(): void
    {
        $this->openCash(10000);
        $manager = User::factory()->role(UserRole::Manager)->withPin('9999')->create();
        $this->orderWith([[$this->product('Caña', 250, $this->drinks), 1]]); // mesa abierta

        $this->asPos($manager)->postJson('/api/cash-session/close', ['counted_cash' => 10000])
            ->assertStatus(409)->assertJsonPath('code', 'open_orders')->assertJsonCount(1, 'orders');

        $this->asPos($manager)->postJson('/api/cash-session/close', ['counted_cash' => 9950, 'force' => true, 'notes' => 'Falta una moneda'])
            ->assertOk()->assertJsonPath('data.z_number', 1)
            ->assertJsonPath('data.report.cash.difference', -50)
            ->assertJsonPath('data.report.notes', 'Falta una moneda');
    }

    public function test_breakdown_must_match_counted_cash(): void
    {
        $this->openCash(0);
        $manager = User::factory()->role(UserRole::Manager)->withPin('9999')->create();

        $this->asPos($manager)->postJson('/api/cash-session/close', ['counted_cash' => 3500, 'breakdown' => ['2000' => 1, '1000' => 1]])->assertUnprocessable();
        $this->asPos($manager)->postJson('/api/cash-session/close', ['counted_cash' => 3500, 'breakdown' => ['2000' => 1, '1000' => 1, '500' => 1]])->assertOk();
    }

    public function test_x_report_in_api_and_print(): void
    {
        $this->openCash(5000);
        $this->checkout($this->orderWith([[$this->product('Caña', 250, $this->drinks), 2]]), [['method' => 'efectivo', 'amount' => 500]]);

        $this->asWaiter()->getJson('/api/cash-session')->assertOk()->assertJsonPath('data.report.cash.expected', 5500);
        $this->asWaiter()->postJson('/api/cash-session/print-x')->assertOk();
        $this->assertStringContainsString('INFORME X', $this->printedText(PrintJob::where('kind', 'informe')->first()->content));
    }
}
