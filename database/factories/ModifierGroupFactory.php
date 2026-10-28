<?php

namespace Database\Factories;

use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModifierGroup> */
class ModifierGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'min_select' => 0,
            'max_select' => null,
            'sort_order' => 0,
        ];
    }
}
