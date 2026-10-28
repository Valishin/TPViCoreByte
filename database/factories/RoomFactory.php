<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Room> */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Salón', 'Terraza', 'Barra', 'Comedor', 'Reservado']),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
