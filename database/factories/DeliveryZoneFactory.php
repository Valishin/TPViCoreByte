<?php

namespace Database\Factories;

use App\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliveryZone> */
class DeliveryZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Zona '.fake()->unique()->word(),
            'delivery_fee' => 200,
            'minimum_order' => 1000,
            'postal_codes' => [],
            'is_active' => true,
        ];
    }
}
