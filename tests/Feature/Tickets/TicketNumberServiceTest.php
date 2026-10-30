<?php

namespace Tests\Feature\Tickets;

use App\Enums\OrderType;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TicketSeries;
use App\Models\User;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Tickets\TicketIssuer;
use App\Services\Tickets\TicketNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TicketNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_consecutive_issues_get_consecutive_numbers_without_gaps(): void
    {
        $this->createSettings();
        $user = User::factory()->create();
        $product = Product::factory()->price(500)->create();

        $tickets = [];
        foreach (range(1, 10) as $i) {
            $order = app(OrderService::class)->open(OrderType::Bar, $user);
            app(OrderLineBuilder::class)->addProduct($order, $product);
            $tickets[] = app(TicketIssuer::class)->issue($order, $user);
        }

        $this->assertSame(range(1, 10), array_map(fn ($t) => $t->number, $tickets));
        $this->assertSame($this->docNumber($tickets[0]->series_code, 10), $tickets[9]->full_number);
        $this->assertSame(10, Setting::current()->defaultTicketSeries->lastNumber());
    }

    public function test_series_are_independent(): void
    {
        $service = app(TicketNumberService::class);
        $a = TicketSeries::factory()->create(['code' => 'A']);
        $b = TicketSeries::factory()->create(['code' => 'B']);

        $result = DB::transaction(fn () => array_map(fn ($n) => $n->full, [$service->next($a), $service->next($a), $service->next($b), $service->next($a)]));

        $this->assertSame([$this->docNumber('A', 1), $this->docNumber('A', 2), $this->docNumber('B', 1), $this->docNumber('A', 3)], $result);
    }

    public function test_failed_issue_does_not_consume_a_number(): void
    {
        $service = app(TicketNumberService::class);
        $series = TicketSeries::factory()->create();

        try {
            DB::transaction(function () use ($service, $series) {
                $service->next($series);
                throw new RuntimeException('Fallo al emitir');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(1, DB::transaction(fn () => $service->next($series))->number);
    }

    public function test_number_is_formatted_with_the_series_digits(): void
    {
        $service = app(TicketNumberService::class);
        $five = TicketSeries::factory()->create(['code' => 'CINCO', 'digits' => 5]);
        $eight = TicketSeries::factory()->create(['code' => 'OCHO', 'digits' => 8]);

        $this->assertSame($this->docNumber('CINCO', 1, 5), DB::transaction(fn () => $service->next($five))->full);
        $this->assertSame($this->docNumber('OCHO', 1, 8), DB::transaction(fn () => $service->next($eight))->full);
        $this->assertMatchesRegularExpression('/^CINCO-\d{2}-00001$/', $this->docNumber('CINCO', 1, 5));
        $this->assertMatchesRegularExpression('/^OCHO-\d{2}-00000001$/', $this->docNumber('OCHO', 1, 8));
    }

    /** Art. 6.1.a: (serie, año, número) no se puede repetir; la base de datos lo impide aunque se salte el servicio. */
    public function test_series_year_number_is_unique_in_the_database(): void
    {
        $this->createSettings();
        $user = User::factory()->create();
        $order = app(OrderService::class)->open(OrderType::Bar, $user);
        app(OrderLineBuilder::class)->addProduct($order, Product::factory()->price(500)->create());
        $ticket = app(TicketIssuer::class)->issue($order, $user);

        $copy = collect($ticket->getAttributes())->except(['id', 'order_id', 'idempotency_key'])->all();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('tickets_ticket_series_id_year_number_unique');
        DB::table('tickets')->insert($copy);
    }
}
