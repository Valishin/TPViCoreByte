<?php

namespace Tests\Feature\Orders;

use App\Enums\DiscountType;
use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderType;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Models\MenuGroup;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderTotalsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTotalsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private OrderLineBuilder $builder;

    private OrderService $orders;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings();
        $this->builder = app(OrderLineBuilder::class);
        $this->orders = app(OrderService::class);
        $this->waiter = User::factory()->create();
    }

    private function order(OrderType $type = OrderType::Bar): Order
    {
        return $this->orders->open($type, $this->waiter);
    }

    private function totals(Order $order)
    {
        return app(OrderTotalsCalculator::class)->calculate($order->refresh());
    }

    public function test_simple_line_times_quantity(): void
    {
        $order = $this->order();
        $this->builder->addProduct($order, Product::factory()->price(250)->create(), quantity: 3);

        $totals = $this->totals($order);

        $this->assertSame(750, $totals->total);
        $this->assertSame([['rate' => 1000, 'base' => 682, 'tax' => 68, 'total' => 750]], $totals->vatBreakdown);
    }

    public function test_variant_with_size_specific_modifier_price(): void
    {
        $pizza = Product::factory()->pizza(900, 1500)->create();
        $extras = ModifierGroup::factory()->create(['name' => 'Extras']);
        $pizza->modifierGroups()->attach($extras);
        $cheese = Modifier::factory()->for($extras, 'group')->create(['name' => 'Extra queso', 'price_delta' => 100]);
        $cheese->variantPrices()->create(['variant_code' => 'familiar', 'price_delta' => 150]);

        $order = $this->order();
        $familiar = $pizza->variants()->where('code', 'familiar')->first();
        $line = $this->builder->addProduct($order, $pizza, $familiar, [$cheese], quantity: 2);

        $this->assertSame(150, $line->modifiers()->first()->price_delta);
        $this->assertSame(3300, $this->totals($order)->total); // (1500 + 150) × 2
    }

    public function test_required_modifier_group_is_enforced(): void
    {
        $steak = Product::factory()->price(1800)->create();
        $steak->modifierGroups()->attach(ModifierGroup::factory()->create(['name' => 'Punto', 'min_select' => 1, 'max_select' => 1]));

        $this->expectException(\InvalidArgumentException::class);
        $this->builder->addProduct($this->order(), $steak);
    }

    public function test_takeaway_vat_rate_is_applied_and_snapshotted(): void
    {
        $beer = Product::factory()->price(300)->create(['vat_rate_takeaway' => 2100]);

        $inside = $this->builder->addProduct($this->order(OrderType::Bar), $beer);
        $takeaway = $this->builder->addProduct($this->order(OrderType::Takeaway), $beer);

        $this->assertSame(1000, $inside->vat_rate);
        $this->assertSame(2100, $takeaway->vat_rate);
    }

    /**
     * Menú a domicilio (×2): pizza (suelta 900, IVA 10 %) + cerveza (suelta 250,
     * IVA 21 % para llevar) con suplemento de 50 €c. Precio del menú 1000.
     * Reparto: 1000 × 900/1150 = 782,6 → 782; 1000 × 250/1150 = 217,4 → 217;
     * resto 1 céntimo a la pizza (mayor importe) → 783 + 217 = 1000.
     */
    public function test_delivery_menu_splits_price_across_component_vat_rates(): void
    {
        $menu = Product::factory()->menu(1000)->create(['name' => 'Menú pizza']);
        $pizza = Product::factory()->price(900, 1000)->create(['name' => 'Pizza individual']);
        $beer = Product::factory()->price(250, 1000)->create(['name' => 'Cerveza', 'vat_rate_takeaway' => 2100]);
        $main = MenuGroup::factory()->for($menu, 'menu')->create(['name' => 'Principal']);
        $drink = MenuGroup::factory()->for($menu, 'menu')->create(['name' => 'Bebida']);
        $pizzaItem = $main->items()->create(['product_id' => $pizza->id]);
        $beerItem = $drink->items()->create(['product_id' => $beer->id, 'supplement' => 50]);

        $zone = DeliveryZone::factory()->create(['delivery_fee' => 200]);
        $customer = Customer::factory()->create();
        $address = CustomerAddress::factory()->for($customer)->create(['delivery_zone_id' => $zone->id]);

        $order = $this->order(OrderType::Delivery);
        $this->orders->attachCustomer($order, $customer, $address);
        $parent = $this->builder->addMenu($order, $menu, [['item' => $pizzaItem], ['item' => $beerItem]], quantity: 2);

        $totals = $this->totals($order);
        [$pizzaLine, $beerLine] = $parent->children()->get()->all();

        $this->assertSame(783, $pizzaLine->allocated_amount);
        $this->assertSame(217, $beerLine->allocated_amount);
        $this->assertSame(1000, $pizzaLine->allocated_amount + $beerLine->allocated_amount);
        $this->assertSame(2100, $beerLine->vat_rate);

        $this->assertSame(1566, $pizzaLine->taxable_amount);   // 783 × 2
        $this->assertSame(534, $beerLine->taxable_amount);     // (217 + 50) × 2
        $this->assertSame(2100, $parent->line_total);
        $this->assertSame(0, $parent->taxable_amount);

        $this->assertSame(200, $totals->deliveryFee);
        $this->assertSame(2300, $totals->total);
        $this->assertSame([
            ['rate' => 1000, 'base' => 1605, 'tax' => 161, 'total' => 1766], // 1566 + 200 de envío
            ['rate' => 2100, 'base' => 441, 'tax' => 93, 'total' => 534],
        ], $totals->vatBreakdown);
    }

    public function test_half_and_half_uses_most_expensive_half(): void
    {
        $order = $this->order();
        [$a, $b] = $this->pizzas(1400, 1600);

        $line = $this->builder->addHalfAndHalf($order, $a, $b, 'familiar');

        $this->assertSame(1600, $line->unit_price);
        $this->assertSame(1600, $this->totals($order)->total);
    }

    /**
     * Media de 1455 y 1600 = 1527,5 → 1528. Reparto entre mitades:
     * 1528 × 1455/3055 = 727,7 → 727; 1528 × 1600/3055 = 800,3 → 800; resto 1 → 801.
     */
    public function test_half_and_half_average_rounds_half_cent_up_and_allocates(): void
    {
        $this->setting(['half_and_half_pricing' => HalfAndHalfPricing::Average]);
        $order = $this->order();
        [$a, $b] = $this->pizzas(1455, 1600);

        $line = $this->builder->addHalfAndHalf($order, $a, $b, 'familiar');
        [$first, $second] = $line->children()->get()->all();

        $this->assertSame(1528, $line->unit_price);
        $this->assertSame([727, 801], [$first->allocated_amount, $second->allocated_amount]);
        $this->assertSame(1528, $this->totals($order)->total);
    }

    public function test_half_and_half_extras_full_or_half_price(): void
    {
        [$a, $b] = $this->pizzas(1400, 1600);
        $extras = ModifierGroup::factory()->create();
        $a->modifierGroups()->attach($extras);
        $cheese = Modifier::factory()->for($extras, 'group')->create(['price_delta' => 150]);

        $full = $this->order();
        $this->builder->addHalfAndHalf($full, $a, $b, 'familiar', modifiersA: [$cheese]);
        $this->assertSame(1750, $this->totals($full)->total); // 1600 + 150

        $this->setting(['half_and_half_modifier_pricing' => HalfAndHalfModifierPricing::Half]);
        $half = $this->order();
        $this->builder->addHalfAndHalf($half, $a, $b, 'familiar', modifiersA: [$cheese]);
        $this->assertSame(1675, $this->totals($half)->total); // 1600 + 75
    }

    public function test_half_and_half_requires_same_size_and_allowed_products(): void
    {
        [$a] = $this->pizzas(1400, 1600);
        $plain = Product::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->builder->addHalfAndHalf($this->order(), $a, $plain, 'familiar');
    }

    public function test_invited_line_is_free_and_excluded_from_vat(): void
    {
        $order = $this->order();
        $this->builder->addProduct($order, Product::factory()->price(300)->create());
        $gin = $this->builder->addProduct($order, Product::factory()->price(800, 2100)->create());

        $gin = $this->orders->inviteLine($gin, $this->waiter, 'Cliente habitual');
        $totals = $this->totals($order);

        $this->assertTrue($gin->is_invitation);
        $this->assertSame(800, $gin->discount_amount);
        $this->assertSame(0, $gin->taxable_amount);
        $this->assertSame(1100, $totals->linesGross);
        $this->assertSame(300, $totals->total);
        $this->assertSame([['rate' => 1000, 'base' => 273, 'tax' => 27, 'total' => 300]], $totals->vatBreakdown);
    }

    public function test_line_percentage_discount(): void
    {
        $order = $this->order();
        $line = $this->builder->addProduct($order, Product::factory()->price(1250)->create(), quantity: 2);

        $line = $this->orders->discountLine($line, DiscountType::Percentage, 1500, $this->waiter); // 15 %

        $this->assertSame(375, $line->discount_amount); // 2500 × 15 %
        $this->assertSame(2125, $this->totals($order)->total);
    }

    /**
     * 10 % sobre el pedido con IVA mixto: 1000 (10 %) + 850 (21 %) + 135 (10 %) = 1985.
     * Descuento 198,5 → 199, repartido 100,25 / 85,2 / 13,5 → 100 / 85 / 13, resto 1 → 101.
     */
    public function test_order_discount_with_mixed_vat_squares_to_the_cent(): void
    {
        $order = $this->order();
        $tapa = $this->builder->addProduct($order, Product::factory()->price(1000)->create());
        $gin = $this->builder->addProduct($order, Product::factory()->price(850, 2100)->create());
        $coffee = $this->builder->addProduct($order, Product::factory()->price(135)->create());

        $this->orders->discountOrder($order, DiscountType::Percentage, 1000, $this->waiter, 'Promo');
        $totals = $this->totals($order);

        $this->assertSame(199, $totals->orderDiscount);
        $this->assertSame([101, 85, 13], [$tapa->fresh()->order_discount_amount, $gin->fresh()->order_discount_amount, $coffee->fresh()->order_discount_amount]);
        $this->assertSame(1786, $totals->total);
        $this->assertSame([
            ['rate' => 1000, 'base' => 928, 'tax' => 93, 'total' => 1021],
            ['rate' => 2100, 'base' => 632, 'tax' => 133, 'total' => 765],
        ], $totals->vatBreakdown);
        $this->assertSame($totals->total, array_sum(array_column($totals->vatBreakdown, 'total')));
        $this->assertSame($totals->total, $totals->taxBase() + $totals->taxAmount());
    }

    public function test_voided_lines_do_not_count(): void
    {
        $order = $this->order();
        $this->builder->addProduct($order, Product::factory()->price(500)->create());
        $wrong = $this->builder->addProduct($order, Product::factory()->price(900)->create());

        $this->orders->voidLine($wrong, $this->waiter, 'Error al marcar');

        $this->assertSame(500, $this->totals($order)->total);
        $this->assertSame(500, $order->fresh()->total);
    }

    /** @return array{0: Product, 1: Product} */
    private function pizzas(int $familyA, int $familyB): array
    {
        return [
            Product::factory()->pizza($familyA - 500, $familyA)->create(['name' => 'Barbacoa']),
            Product::factory()->pizza($familyB - 500, $familyB)->create(['name' => 'Cuatro quesos']),
        ];
    }

    private function setting(array $values): void
    {
        \App\Models\Setting::current()->update($values);
    }
}
