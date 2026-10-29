<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notas rápidas de cocina: lista ordenada de textos cortos.
        Schema::table('categories', function (Blueprint $table) {
            $table->jsonb('quick_notes')->default('[]');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->jsonb('quick_notes')->default('[]');              // generales: para todas las categorías
            $table->boolean('show_onscreen_keyboard_pos')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('quick_notes'));
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn(['quick_notes', 'show_onscreen_keyboard_pos']));
    }
};
