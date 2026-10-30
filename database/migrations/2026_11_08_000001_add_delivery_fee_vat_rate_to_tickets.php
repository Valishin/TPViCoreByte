<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de IVA aplicado a los gastos de envío, copiado en el ticket (como el de
 * las líneas): la factura completa los muestra como una línea más con su
 * precio sin IVA y su tipo (art. 6.1.f y g RD 1619/2012). Los tickets ya
 * emitidos no se tocan (son inmutables): sin valor, se usa el tipo del ajuste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedSmallInteger('delivery_fee_vat_rate')->nullable()->after('delivery_fee');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('delivery_fee_vat_rate');
        });
    }
};
