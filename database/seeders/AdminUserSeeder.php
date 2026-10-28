<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuarios de DESARROLLO para entrar en /admin (email + contraseña).
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
                'role' => UserRole::Admin,
                'password' => env('DEV_ADMIN_PASSWORD', 'admin1234'),
                'pin' => '0000',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        User::updateOrCreate(
            ['email' => 'encargado@tpv.test'],
            [
                'name' => 'Encargado (desarrollo)',
                'role' => UserRole::Manager,
                'password' => env('DEV_ADMIN_PASSWORD', 'admin1234'),
                'pin' => '9999',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
