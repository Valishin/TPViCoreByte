<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'price' => fake()->numberBetween(150, 1800),
            'vat_rate' => 1000,
            'vat_rate_takeaway' => null,
            'type' => ProductType::Simple,
            'allows_half_and_half' => false,
            'allergens' => [],
            'is_active' => true,
            'is_available' => true,
            'sort_order' => 0,
        ];
    }

    public function price(int $cents, int $vatRate = 1000): static
    {
        return $this->state(fn () => ['price' => $cents, 'vat_rate' => $vatRate]);
    }

    public function menu(int $cents): static
    {
        return $this->state(fn () => ['type' => ProductType::Menu, 'price' => $cents]);
    }

    /** Pizza con variantes por tamaño que admite mitad y mitad. */
    public function pizza(int $medium, int $family): static
    {
        return $this->state(fn () => ['type' => ProductType::Variants, 'allows_half_and_half' => true, 'price' => $medium])
            ->afterCreating(function (Product $product) use ($medium, $family) {
                $product->variants()->createMany([
                    ['code' => 'mediana', 'name' => 'Mediana', 'price' => $medium, 'sort_order' => 1],
                    ['code' => 'familiar', 'name' => 'Familiar', 'price' => $family, 'sort_order' => 2],
                ]);
            });
    }
}
