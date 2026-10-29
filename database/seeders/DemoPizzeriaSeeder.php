<?php

namespace Database\Seeders;

use App\Enums\Allergen as A;
use App\Enums\OrderType;
use App\Enums\PrinterType;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Product;

/**
 * Dataset "pizzería": pizzas con tamaños y mitad y mitad, extras con precio
 * por tamaño, 2 menús, bebidas y postres, 3 zonas, 15 clientes, 2 repartidores.
 */
class DemoPizzeriaSeeder extends DemoSeeder
{
    public function run(): void
    {
        fake()->seed(2026); // datos de clientes reproducibles

        $this->setUpLocal('Pizzería Bella Napoli', [OrderType::Takeaway, OrderType::Delivery]);

        $this->createStaff(UserRole::Waiter, ['Carlos (mostrador)' => '1111']);
        $this->createStaff(UserRole::Courier, ['Javi (reparto)' => '4444', 'Sara (reparto)' => '5555']);

        $kitchen = $this->printer('Cocina', PrinterType::Kitchen, '192.168.1.202');
        $counter = $this->printer('Mostrador', PrinterType::Ticket, '192.168.1.203', 32);

        // Pizzas: [nombre, mediana, familiar, alérgenos]
        $base = [A::Gluten, A::Milk];
        $pizzaCategory = $this->category('Pizzas', '#dc2626', 1, $kitchen, ['Bien hecha', 'Poco hecha', 'Cortada', 'Sin cortar', 'Sin orégano']);
        $pizzas = [];
        foreach ([
            ['Margarita', 850, 1350, $base],
            ['Barbacoa', 1050, 1600, [...$base, A::Mustard, A::Sulphites]],
            ['Cuatro quesos', 1050, 1600, $base],
            ['Carbonara', 1050, 1600, [...$base, A::Eggs]],
            ['Hawaiana', 950, 1500, $base],
            ['Pepperoni', 1000, 1550, [...$base, A::Sulphites]],
            ['Vegetal', 950, 1500, $base],
            ['Atún', 1000, 1550, [...$base, A::Fish]],
            ['Prosciutto e funghi', 1050, 1600, $base],
            ['Marinera', 1150, 1750, [...$base, A::Crustaceans, A::Molluscs]],
            ['Calzone', 1100, 1650, [...$base, A::Eggs]],
            ['Diavola', 1050, 1600, [...$base, A::Sulphites]],
        ] as $i => [$name, $medium, $family, $allergens]) {
            $pizza = Product::create([
                'category_id' => $pizzaCategory->id, 'name' => $name, 'price' => $medium, 'vat_rate' => 1000,
                'type' => ProductType::Variants, 'allows_half_and_half' => $name !== 'Calzone',
                'allergens' => $allergens, 'sort_order' => $i,
            ]);
            $pizza->variants()->createMany([
                ['code' => 'mediana', 'name' => 'Mediana', 'price' => $medium, 'sort_order' => 1],
                ['code' => 'familiar', 'name' => 'Familiar', 'price' => $family, 'sort_order' => 2],
            ]);
            $pizzas[$name] = $pizza;
        }

        // Extras con precio según tamaño.
        $extras = $this->modifierGroup('Ingredientes extra', 0, 5, [
            'Extra de queso' => 100, 'Bacon' => 120, 'Champiñones' => 100, 'Jamón york' => 120, 'Aceitunas' => 80, 'Pepperoni' => 120,
        ]);
        foreach ($extras->modifiers as $modifier) {
            $modifier->variantPrices()->create(['variant_code' => 'familiar', 'price_delta' => (int) round($modifier->price_delta * 1.5)]);
        }
        $remove = $this->modifierGroup('Quitar ingredientes', 0, null, ['Sin cebolla' => 0, 'Sin aceitunas' => 0, 'Sin orégano' => 0, 'Sin tomate' => 0]);
        $dough = $this->modifierGroup('Masa', 0, 1, ['Masa fina' => 0, 'Masa sin gluten' => 200]);
        foreach ($pizzas as $pizza) {
            $pizza->modifierGroups()->attach([$extras->id => ['sort_order' => 1], $remove->id => ['sort_order' => 2], $dough->id => ['sort_order' => 3]]);
        }

        $this->products($this->category('Entrantes', '#f97316', 2, $kitchen, ['Salsa aparte', 'Sin picante', 'Bien caliente']), [
            ['Pan de ajo', 350, [A::Gluten, A::Milk]],
            ['Alitas de pollo (8)', 650, [A::Gluten, A::Celery]],
            ['Nachos con queso', 600, [A::Milk]],
            ['Ensalada César', 750, [A::Gluten, A::Eggs, A::Fish, A::Milk]],
        ]);

        // IVA: agua 10 % siempre; refrescos azucarados y alcohol 10 % en local y 21 % para llevar.
        $alcohol = ['vat_rate_takeaway' => 2100];
        $sugary = ['vat_rate_takeaway' => 2100];
        $drinks = $this->products($this->category('Bebidas', '#0ea5e9', 3, $counter, ['Sin hielo', 'Con limón', 'Del tiempo', 'Bien fría']), [
            ['Coca-Cola 33 cl', 200, [], $sugary],
            ['Coca-Cola Zero 33 cl', 200, []],
            ['Fanta de naranja 33 cl', 200, [], $sugary],
            ['Agua 50 cl', 150, []],
            ['Cerveza 33 cl', 220, [A::Gluten], $alcohol],
            ['Cerveza sin alcohol 33 cl', 220, [A::Gluten]],
            ['Lambrusco (botella)', 900, [A::Sulphites], $alcohol],
            ['Coca-Cola 2 l', 350, [], $sugary],
        ]);
        $desserts = $this->products($this->category('Postres', '#a855f7', 4, $counter, ['Con nata', 'Sin nata', 'Dos cucharas']), [
            ['Tiramisú', 400, [A::Gluten, A::Milk, A::Eggs]],
            ['Brownie', 380, [A::Gluten, A::Milk, A::Eggs, A::Nuts]],
            ['Helado tarrina', 350, [A::Milk]],
            ['Coulant de chocolate', 450, [A::Gluten, A::Milk, A::Eggs, A::Soy]],
        ]);

        // Menús: el precio del padre se reparte entre componentes al facturar.
        $menus = $this->category('Menús', '#16a34a', 0, null);
        $premium = ['Marinera' => 100, 'Calzone' => 100];
        $smallDrinks = array_slice($drinks, 0, 6, true);

        $individual = Product::create(['category_id' => $menus->id, 'name' => 'Menú Individual', 'price' => 1290, 'vat_rate' => 1000, 'type' => ProductType::Menu, 'sort_order' => 1]);
        $this->menuGroup($individual, 'Pizza mediana', 1, 1, $pizzas, 'mediana', $premium);
        $this->menuGroup($individual, 'Bebida', 1, 1, $smallDrinks);
        $this->menuGroup($individual, 'Postre', 1, 1, $desserts);

        $couple = Product::create(['category_id' => $menus->id, 'name' => 'Menú Pareja', 'price' => 2490, 'vat_rate' => 1000, 'type' => ProductType::Menu, 'sort_order' => 2]);
        $this->menuGroup($couple, 'Pizza familiar', 1, 1, $pizzas, 'familiar', $premium);
        $this->menuGroup($couple, 'Bebidas', 2, 2, $smallDrinks + ['Coca-Cola 2 l' => $drinks['Coca-Cola 2 l']]);
        $this->menuGroup($couple, 'Postre', 1, 1, $desserts, supplements: ['Coulant de chocolate' => 50]);

        // Zonas de reparto.
        $zones = [
            DeliveryZone::create(['name' => 'Centro', 'delivery_fee' => 0, 'minimum_order' => 1200, 'postal_codes' => ['28001', '28004', '28013']]),
            DeliveryZone::create(['name' => 'Ensanche', 'delivery_fee' => 150, 'minimum_order' => 1500, 'postal_codes' => ['28010', '28015']]),
            DeliveryZone::create(['name' => 'Afueras', 'delivery_fee' => 250, 'minimum_order' => 2000, 'postal_codes' => ['28035', '28050']]),
        ];

        // 15 clientes con 1-2 direcciones.
        foreach (range(1, 15) as $n) {
            $customer = Customer::create([
                'phone' => '6'.fake()->unique()->numerify('########'),
                'name' => fake()->firstName().' '.fake()->lastName(),
                'notes' => $n % 5 === 0 ? 'Llamar al llegar, el timbre no funciona' : null,
            ]);
            foreach (range(1, $n % 3 === 0 ? 2 : 1) as $a) {
                $zone = fake()->randomElement($zones);
                $customer->addresses()->create([
                    'label' => $a === 1 ? 'Casa' : 'Trabajo',
                    'street' => fake()->streetName(),
                    'number' => (string) fake()->numberBetween(1, 120),
                    'floor_door' => fake()->randomElement([null, '1º A', '2º B', '4º izq.', 'Bajo']),
                    'postal_code' => fake()->randomElement($zone->postal_codes),
                    'city' => 'Madrid',
                    'instructions' => fake()->optional(0.3)->randomElement(['Portal verde', 'Dejar en conserjería', 'Segundo portal']),
                    'delivery_zone_id' => $zone->id,
                    'is_default' => $a === 1,
                ]);
            }
        }
    }

    /**
     * @param  array<string, Product>  $products
     * @param  array<string, int>  $supplements
     */
    private function menuGroup(Product $menu, string $name, int $min, int $max, array $products, ?string $variantCode = null, array $supplements = []): void
    {
        $group = $menu->menuGroups()->create(['name' => $name, 'min_select' => $min, 'max_select' => $max, 'sort_order' => $menu->menuGroups()->count()]);
        $i = 0;
        foreach ($products as $productName => $product) {
            $group->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variantCode ? $product->variants()->where('code', $variantCode)->value('id') : null,
                'supplement' => $supplements[$productName] ?? 0,
                'sort_order' => $i++,
            ]);
        }
    }
}
