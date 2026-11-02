<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «Ir a la TPV» en la cabecera de /admin: en la misma pestaña (por defecto) o en otra. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->boolean('admin_pos_link_new_tab')->default(false));
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('admin_pos_link_new_tab'));
    }
};
