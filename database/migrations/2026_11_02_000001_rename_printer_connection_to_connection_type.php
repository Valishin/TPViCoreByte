<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `connection` chocaba con la propiedad $connection de Eloquent (nombre de la
 * conexión a la BD): dentro del modelo no se podía leer la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', fn (Blueprint $t) => $t->renameColumn('connection', 'connection_type'));
    }

    public function down(): void
    {
        Schema::table('printers', fn (Blueprint $t) => $t->renameColumn('connection_type', 'connection'));
    }
};
