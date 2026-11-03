<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de tiempo en la TPV (discretos, se quitan con un toque): pedidos de
 * llevar / domicilio cerca de la hora prometida o tarde, y mesas abiertas
 * demasiado tiempo. Se activan y ajustan en /admin → Ajustes del local.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            $t->boolean('time_alerts_enabled')->default(true);
            $t->unsignedSmallInteger('time_alert_takeaway_minutes')->default(10);   // quedan ≤ N min para la hora prometida
            $t->unsignedSmallInteger('time_alert_table_minutes')->default(120);     // mesa abierta ≥ N min (0 = sin aviso)
        });
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn(['time_alerts_enabled', 'time_alert_takeaway_minutes', 'time_alert_table_minutes']));
    }
};
