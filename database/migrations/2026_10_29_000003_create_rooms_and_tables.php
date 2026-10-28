<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->unsignedSmallInteger('seats')->default(4);
            // Posición y tamaño en el plano (unidades de la cuadrícula del editor).
            $table->integer('pos_x')->default(0);
            $table->integer('pos_y')->default(0);
            $table->unsignedSmallInteger('width')->default(1);
            $table->unsignedSmallInteger('height')->default(1);
            $table->string('shape', 20)->default('cuadrada');
            // Solo lo cambian los servicios de pedidos (App\Services\Orders\OrderService).
            $table->string('status', 20)->default('libre');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['room_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
        Schema::dropIfExists('rooms');
    }
};
