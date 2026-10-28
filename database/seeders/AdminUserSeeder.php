<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuario administrador de DESARROLLO para entrar en /admin.
 * No se ejecuta en producción.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('AdminUserSeeder omitido en producción.');

            return;
        }

        User::updateOrCreate(
            ['email' => env('DEV_ADMIN_EMAIL', 'admin@tpv.test')],
            [
                'name' => 'Admin (desarrollo)',
                'password' => env('DEV_ADMIN_PASSWORD', 'admin1234'),
                'email_verified_at' => now(),
            ],
        );
    }
}
