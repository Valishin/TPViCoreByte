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

        $numbers = [];
        foreach (range(1, 10) as $i) {
            $order = app(OrderService::class)->open(OrderType::Bar, $user);
            app(OrderLineBuilder::class)->addProduct($order, $product);
            $numbers[] = app(TicketIssuer::class)->issue($order, $user)->number;
        }

        $this->assertSame(range(1, 10), $numbers);
        $this->assertSame(10, Setting::current()->defaultTicketSeries->fresh()->last_number);
    }

    public function test_series_are_independent(): void
    {
        $service = app(TicketNumberService::class);
        $a = TicketSeries::factory()->create(['code' => 'A']);
        $b = TicketSeries::factory()->create(['code' => 'B']);

        $result = DB::transaction(fn () => [$service->next($a), $service->next($a), $service->next($b), $service->next($a)]);

        $this->assertSame([1, 2, 1, 3], $result);
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

        $this->assertSame(1, DB::transaction(fn () => $service->next($series)));
    }
}
