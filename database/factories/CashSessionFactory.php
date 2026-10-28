<?php

namespace Database\Factories;

use App\Enums\CashSessionStatus;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashSession> */
class CashSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'status' => CashSessionStatus::Open,
            'opened_by' => User::factory(),
            'opened_at' => now(),
            'opening_float' => 10000,
        ];
    }
}
