<?php

namespace Database\Factories;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Modifier> */
class ModifierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'modifier_group_id' => ModifierGroup::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'price_delta' => 0,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
