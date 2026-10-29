<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lo que el repartidor dice haber cobrado al entregar ([{method, amount}]).
        // No es un cobro: el dinero se liquida en caja, que lo propone por defecto.
        Schema::table('orders', function (Blueprint $table) {
            $table->json('courier_reported_payments')->nullable()->after('delivered_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('courier_reported_payments'));
    }
};
