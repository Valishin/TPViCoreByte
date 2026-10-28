<?php

namespace Database\Seeders;

use App\Enums\DeliveryStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Tickets\PaymentService;
use App\Services\Tickets\TicketIssuer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Ventas de demo de los últimos 7 días (con cierre de caja diario) sobre el
 * dataset cargado. Usa los servicios reales, así que todo cuadra.
 *
 *   sail artisan db:seed --class=DemoSalesSeeder
 */
class DemoSalesSeeder extends Seeder
{
    public function run(OrderService $orders, OrderLineBuilder $lines, TicketIssuer $issuer, PaymentService $payments, CashSessionService $cash): void
    {
        fake()->seed(7);
        $types = Setting::current()->enabled_order_types;
        $staff = User::query()->whereIn('role', [UserRole::Waiter, UserRole::Manager])->get();
        $courier = User::query()->where('role', UserRole::Courier)->first();
        $products = Product::query()->where('type', ProductType::Simple)->whereDoesntHave('modifierGroups', fn ($q) => $q->where('min_select', '>', 0))->get();
        $pizzas = Product::query()->where('allows_half_and_half', true)->get();

        $today = today(); // antes de mover el reloj con setTestNow
        foreach (range(6, 0) as $daysAgo) {
            $day = $today->copy()->subDays($daysAgo)->setTime(12, 0);
            Carbon::setTestNow($day);
            $session = $cash->open($staff->first(), 15000);

            foreach (range(1, fake()->numberBetween(6, 12)) as $n) {
                Carbon::setTestNow($day->copy()->addMinutes($n * 25));
                $type = $types->random();
                $user = $staff->random();

                if ($type === OrderType::Table) {
                    $table = Table::query()->where('status', 'libre')->inRandomOrder()->first();
                    $order = $orders->open($type, $user, $table, guests: fake()->numberBetween(1, 6));
                } else {
                    $order = $orders->open($type, $user);
                }

                if ($type->isTakeaway()) {
                    $customer = Customer::query()->has('addresses')->inRandomOrder()->first();
                    if ($customer) {
                        $orders->attachCustomer($order, $customer, $type === OrderType::Delivery ? $customer->addresses->first() : null);
                    }
                    $order->update(['courier_id' => $type === OrderType::Delivery ? $courier?->id : null, 'promised_at' => now()->addMinutes(40)]);
                }

                if ($pizzas->count() >= 2 && fake()->boolean(60)) {
                    [$a, $b] = $pizzas->random(2)->all();
                    $lines->addHalfAndHalf($order, $a, $b, fake()->randomElement(['mediana', 'familiar']));
                }
                foreach ($products->random(min($products->count(), fake()->numberBetween(2, 4))) as $product) {
                    $lines->addProduct($order, $product, quantity: fake()->numberBetween(1, 3));
                }

                // Algún pedido se queda abierto hoy.
                if ($daysAgo === 0 && $n > 4 && fake()->boolean(40)) {
                    continue;
                }

                $ticket = $issuer->issue($order->refresh(), $user, $session);
                // Domicilios de hoy: alguno pendiente de cobro (lo traerá el repartidor).
                if ($type === OrderType::Delivery && $daysAgo === 0 && fake()->boolean(50)) {
                    continue;
                }
                $method = fake()->randomElement([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Card, PaymentMethod::Bizum]);
                $tendered = $method === PaymentMethod::Cash ? (int) ceil($ticket->total / 500) * 500 : null;
                $payments->register($ticket, $method, $ticket->total, $user, $tendered, $session);
                if ($type->isTakeaway()) {
                    $order->update(['delivery_status' => DeliveryStatus::Delivered]);
                }
            }

            if ($daysAgo > 0) {
                Carbon::setTestNow($day->copy()->setTime(23, 30));
                $expected = $session->opening_float + (int) $session->payments()->where('method', PaymentMethod::Cash)->sum('amount');
                $cash->close($session, $staff->first(), $expected + fake()->randomElement([0, 0, -50, 100]));
            }
        }

        Carbon::setTestNow();
    }
}
