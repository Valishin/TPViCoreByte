<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => OrderType::Bar,
            'status' => OrderStatus::Open,
            'opened_by' => User::factory(),
            'opened_at' => now(),
            'delivery_fee' => 0,
        ];
    }

    public function type(OrderType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
