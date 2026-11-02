<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Puesta en marcha desde /admin:
 *  - setup_code_hash: código de un solo uso para «Crear administrador» (lo
 *    genera install.sh; se borra al crear el primer administrador);
 *  - setup_steps: pasos confirmados u omitidos del asistente {paso: done|skipped};
 *  - setup_seen_at: el asistente ya se abrió solo una vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('setup_code_hash')->nullable();
            $table->json('setup_steps')->nullable();
            $table->timestamp('setup_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn(['setup_code_hash', 'setup_steps', 'setup_seen_at']));
    }
};
