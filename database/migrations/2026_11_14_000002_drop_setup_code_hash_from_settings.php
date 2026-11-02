<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sin código de configuración: el primer administrador se crea en /admin mientras no hay usuarios. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('setup_code_hash'));
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->string('setup_code_hash')->nullable());
    }
};
