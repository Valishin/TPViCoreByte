<?php

namespace Database\Factories;

use App\Models\TicketSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketSeries> */
class TicketSeriesFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'name' => 'Serie '.fake()->word(),
            'is_rectifying' => false,
            'last_number' => 0,
            'is_active' => true,
        ];
    }
}
