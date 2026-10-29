<?php

namespace App\Jobs;

use App\Services\Backup\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Copia de seguridad en segundo plano (p. ej. justo después de un cierre Z). */
class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $reason = 'manual') {}

    public function handle(BackupService $backups): void
    {
        $backups->run($this->reason);
    }
}
