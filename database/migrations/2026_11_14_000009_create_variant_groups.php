<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos de variantes («Tamaños de pizza»: Individual, Mediana, Familiar…) para
 * no escribirlas en cada producto. Al elegir un grupo en un producto, sus filas
 * se COPIAN como variantes del producto (que siguen siendo suyas y editables):
 * cambiar el grupo después no toca productos ni pedidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('variant_group_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('variant_group_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('code', 40);                     // código de tamaño (igual en todos los productos)
            $t->integer('price')->nullable();            // céntimos; vacío = se pone a mano en cada producto
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_group_items');
        Schema::dropIfExists('variant_groups');
    }
};
