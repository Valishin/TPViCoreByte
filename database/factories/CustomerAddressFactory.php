<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAddress> */
class CustomerAddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => fake()->randomElement(['Casa', 'Trabajo']),
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'floor_door' => fake()->randomElement([null, '1º A', '3º B', 'Bajo']),
            'postal_code' => fake()->postcode(),
            'city' => fake()->city(),
            'instructions' => null,
            'delivery_zone_id' => null,
            'is_default' => true,
        ];
    }
}
