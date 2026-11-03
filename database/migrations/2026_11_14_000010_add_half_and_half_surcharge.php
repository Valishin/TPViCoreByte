<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suplemento opcional por pedir un producto mitad y mitad (p. ej. +1 € la
 * pizza). Se fija en el producto y la línea del pedido lo copia (snapshot):
 * cambiarlo en la carta no altera pedidos ya hechos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedInteger('half_and_half_surcharge')->default(0);   // céntimos
        });
        Schema::table('order_lines', function (Blueprint $t) {
            $t->unsignedInteger('half_and_half_surcharge')->default(0);   // céntimos, en la línea madre
        });
        // El ticket lo copia para imprimirlo (solo se añade la columna: los tickets emitidos no cambian).
        Schema::table('ticket_lines', function (Blueprint $t) {
            $t->unsignedInteger('half_and_half_surcharge')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_lines', fn (Blueprint $t) => $t->dropColumn('half_and_half_surcharge'));
        Schema::table('order_lines', fn (Blueprint $t) => $t->dropColumn('half_and_half_surcharge'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('half_and_half_surcharge'));
    }
};
