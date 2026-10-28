<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'phone' => '6'.fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'notes' => null,
        ];
    }
}
