<?php

namespace Database\Factories;

use App\Enums\PrinterType;
use App\Models\Printer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Printer> */
class PrinterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Impresora '.fake()->word(),
            'type' => PrinterType::Kitchen,
            'host' => fake()->localIpv4(),
            'port' => 9100,
            'columns' => 48,
            'codepage' => 16,
            'profile' => 'default',
            'is_active' => true,
        ];
    }
}
