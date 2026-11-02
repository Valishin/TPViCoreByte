<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia en un disco aparte (USB): el disco elegido en /admin (por su UUID) y,
 * en cada copia, si ya está en ese disco (copiada | pendiente | no_conectado |
 * error | sin_disco).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->string('backup_disk_uuid', 64)->nullable());
        Schema::table('backup_runs', function (Blueprint $t) {
            $t->string('disk_status', 20)->default('sin_disco');
            $t->timestamp('disk_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', fn (Blueprint $t) => $t->dropColumn(['disk_status', 'disk_at']));
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('backup_disk_uuid'));
    }
};
