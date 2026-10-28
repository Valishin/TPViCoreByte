<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'code' => fake()->unique()->slug(1),
            'name' => ucfirst(fake()->word()),
            'price' => fake()->numberBetween(500, 2000),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
