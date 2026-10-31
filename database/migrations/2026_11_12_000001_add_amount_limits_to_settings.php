<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importes máximos razonables (céntimos) para el TPV: por encima, la pantalla
 * pide confirmación con el importe en grande antes de seguir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('max_opening_float')->default(200000);   // fondo de caja: 2.000 €
            $table->unsignedInteger('max_cash_count')->default(1000000);     // arqueo: 10.000 €
            $table->unsignedInteger('max_cash_tendered')->default(100000);   // efectivo entregado: 1.000 €
            $table->unsignedInteger('max_tip')->default(5000);               // propina: 50 €
        });
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn(['max_opening_float', 'max_cash_count', 'max_cash_tendered', 'max_tip']));
    }
};
