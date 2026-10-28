<?php

namespace Database\Factories;

use App\Models\MenuGroup;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MenuGroup> */
class MenuGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->menu(1200),
            'name' => ucfirst(fake()->word()),
            'min_select' => 1,
            'max_select' => 1,
            'sort_order' => 0,
        ];
    }
}
