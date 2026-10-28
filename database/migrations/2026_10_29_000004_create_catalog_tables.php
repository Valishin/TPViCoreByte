<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20); // ticket | cocina | barra
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->default(9100);
            $table->unsignedSmallInteger('columns')->default(48);
            $table->unsignedSmallInteger('codepage')->nullable();
            $table->string('profile', 50)->default('default');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('short_name', 40)->nullable(); // para cocina
            $table->unsignedInteger('price')->default(0); // céntimos, IVA incluido
            $table->unsignedSmallInteger('vat_rate')->default(1000); // puntos básicos
            $table->unsignedSmallInteger('vat_rate_takeaway')->nullable(); // para llevar / domicilio
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete(); // sobrescribe la de la categoría
            $table->string('type', 20)->default('simple');
            $table->boolean('allows_half_and_half')->default(false);
            $table->json('allergens')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true); // agotado temporal
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30); // casa tamaños entre productos (mitad y mitad, extras)
            $table->string('name');
            $table->unsignedInteger('price');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'code']);
        });

        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('min_select')->default(0);
            $table->unsignedSmallInteger('max_select')->nullable(); // null = sin límite
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('price_delta')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('modifier_variant_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_id')->constrained()->cascadeOnDelete();
            $table->string('variant_code', 30);
            $table->integer('price_delta');
            $table->timestamps();

            $table->unique(['modifier_id', 'variant_code']);
        });

        Schema::create('product_modifier_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['product_id', 'modifier_group_id']);
        });

        Schema::create('menu_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete(); // producto tipo menú
            $table->string('name');
            $table->unsignedSmallInteger('min_select')->default(1);
            $table->unsignedSmallInteger('max_select')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('menu_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('supplement')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['menu_group_items', 'menu_groups', 'product_modifier_group', 'modifier_variant_prices', 'modifiers', 'modifier_groups', 'product_variants', 'products', 'categories', 'printers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
