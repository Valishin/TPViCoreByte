<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «Permitir acceso SSH por Tailscale»: desactivado salvo que el admin lo active a mano. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->boolean('tailscale_ssh')->default(false));
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('tailscale_ssh'));
    }
};
