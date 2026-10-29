<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use InvalidArgumentException;

/**
 * Carga un dataset de demo según DEMO_DATASET (bar | pizzeria | pizzeria_mixta).
 * Los datasets son excluyentes: cambia de uno a otro con migrate:fresh.
 *
 *   DEMO_DATASET=pizzeria sail artisan migrate:fresh --seed
 *   sail artisan migrate:fresh --seeder=DemoPizzeriaSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $dataset = env('DEMO_DATASET', 'bar');

        $this->call(match ($dataset) {
            'bar' => DemoBarSeeder::class,
            'pizzeria' => DemoPizzeriaSeeder::class,
            'pizzeria_mixta' => DemoPizzeriaMixtaSeeder::class,
            default => throw new InvalidArgumentException("DEMO_DATASET desconocido: {$dataset} (usa bar, pizzeria o pizzeria_mixta)"),
        });
    }
}
