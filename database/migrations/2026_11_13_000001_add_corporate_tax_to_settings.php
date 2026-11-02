<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 2 de la puesta en marcha: ¿el titular declara el Impuesto sobre
 * Sociedades? Decide la fecha de obligación de VERI*FACTU (1/1/2027 o
 * 1/7/2027). Null = sin contestar (en modo cliente no se emite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->boolean('corporate_tax')->nullable());

        // Instalaciones anteriores con la fecha en el .env: se conserva como respuesta.
        $date = env('VERIFACTU_OBLIGATION_DATE');
        if ($date !== null && $date !== '') {
            DB::table('settings')->update(['corporate_tax' => $date === config('verifactu.obligation_dates.sociedades')]);
        }
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('corporate_tax'));
    }
};
