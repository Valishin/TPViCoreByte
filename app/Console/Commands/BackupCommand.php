<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tpv:backup
    {--reason=manual : diaria | cierre_z | arranque | actualizacion | manual}
    {--if-older= : Solo si la última copia correcta tiene más de estas horas}
    {--upload-only : Solo reintentar la subida remota pendiente}')]
#[Description('Copia de seguridad cifrada de la base de datos (con rotación y subida remota)')]
class BackupCommand extends Command
{
    public function handle(BackupService $backups): int
    {
        if ($this->option('upload-only')) {
            $ok = $backups->upload();
            $this->line($ok ? 'Subida al destino remoto al día.' : 'Sin destino remoto o subida fallida (se reintentará).');

            return self::SUCCESS;
        }

        $hours = $this->option('if-older');
        if ($hours !== null && ! $backups->isOlderThan((int) $hours)) {
            $this->line("Hay una copia de menos de {$hours} h: no hace falta otra.");

            return self::SUCCESS;
        }

        $run = $backups->run($this->option('reason'));
        if ($run->status !== 'ok') {
            $this->error("Copia fallida: {$run->error}");

            return self::FAILURE;
        }
        $this->info("Copia {$run->file} (".number_format($run->size / 1024, 0, ',', '.')." KB) · remoto: {$run->remote_status}");

        return self::SUCCESS;
    }
}
