<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Liquidar a un repartidor: imprimir o no el comprobante (por defecto, sí, como hasta ahora). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->boolean('print_courier_settlement')->default(true));
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('print_courier_settlement'));
    }
};
