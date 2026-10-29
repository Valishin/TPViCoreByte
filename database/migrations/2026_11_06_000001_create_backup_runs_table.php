<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de copias de seguridad (local y remota) para el aviso de /admin.
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reason', 20);            // diaria | cierre_z | arranque | actualizacion | manual
            $table->string('file')->nullable();      // nombre del fichero cifrado
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status', 20);            // ok | error
            $table->string('remote_status', 20);     // pendiente | subida | error | sin_destino
            $table->timestamp('remote_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['remote_status', 'remote_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
