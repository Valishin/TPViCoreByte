<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/** Una copia de seguridad: estado local, de la subida remota y del disco aparte. */
#[Unguarded]
class BackupRun extends Model
{
    protected function casts(): array
    {
        return ['size' => 'integer', 'remote_at' => 'datetime', 'disk_at' => 'datetime'];
    }
}
