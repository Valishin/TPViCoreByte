<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\SetupCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Código de un solo uso para «Crear administrador» en /admin (lo lanza install.sh). */
#[Signature('tpv:setup-code {--json : Salida en JSON}')]
#[Description('Genera el código de configuración para crear el primer administrador en /admin')]
class SetupCodeCommand extends Command
{
    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->option('json') ? $this->line(json_encode(['code' => null])) : $this->info('El administrador ya existe: no hace falta código.');

            return self::SUCCESS;
        }
        $code = SetupCode::issue();
        $this->option('json') ? $this->line(json_encode(['code' => $code])) : $this->line("Código de configuración: {$code}");

        return self::SUCCESS;
    }
}
