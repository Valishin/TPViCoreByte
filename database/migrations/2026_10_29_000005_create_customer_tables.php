<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('delivery_fee')->default(0);
            $table->unsignedInteger('minimum_order')->default(0);
            $table->json('postal_codes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->nullable()->unique(); // E.164; null al anonimizar
            $table->string('name')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50)->nullable();
            $table->string('street');
            $table->string('number', 20)->nullable();
            $table->string('floor_door', 50)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('delivery_zones');
    }
};
