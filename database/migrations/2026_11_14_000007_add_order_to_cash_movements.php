<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** El cambio que se lleva el repartidor desde la caja para un pedido («cambio para 50 €»): a qué pedido va. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', fn (Blueprint $t) => $t->foreignId('order_id')->nullable()->after('courier_id')->constrained()->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('cash_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('order_id'));
    }
};
