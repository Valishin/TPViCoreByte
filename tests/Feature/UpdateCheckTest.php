<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_update_when_everything_is_closed(): void
    {
        $this->createSettings();
        $this->artisan('tpv:update-check')->assertSuccessful();
    }

    public function test_blocks_with_open_cash_open_orders_or_unsettled_deliveries(): void
    {
        $this->createSettings();
        $manager = User::factory()->role(UserRole::Manager)->withPin('9999')->create();
        app(CashSessionService::class)->open($manager, 0);
        Order::create(['type' => OrderType::Bar, 'status' => OrderStatus::Open, 'opened_by' => $manager->id, 'opened_at' => now()]);
        Order::create(['type' => OrderType::Delivery, 'status' => OrderStatus::Closed, 'opened_by' => $manager->id, 'opened_at' => now(),
            'delivery_status' => DeliveryStatus::Delivered]);

        $this->artisan('tpv:update-check')->assertFailed()
            ->expectsOutputToContain('La caja está abierta')
            ->expectsOutputToContain('1 pedido(s) abierto(s)')
            ->expectsOutputToContain('1 domicilio(s) pendiente(s) de liquidar');
    }
}
