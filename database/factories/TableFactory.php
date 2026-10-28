<?php

namespace Database\Factories;

use App\Enums\TableShape;
use App\Enums\TableStatus;
use App\Models\Room;
use App\Models\Table;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Table> */
class TableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'name' => (string) fake()->unique()->numberBetween(1, 999),
            'seats' => fake()->randomElement([2, 4, 6]),
            'pos_x' => fake()->numberBetween(0, 10),
            'pos_y' => fake()->numberBetween(0, 10),
            'shape' => TableShape::Square,
            'status' => TableStatus::Free,
            'is_active' => true,
        ];
    }
}
