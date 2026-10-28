<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Series de numeración. La fila se bloquea (FOR UPDATE) al emitir un ticket.
        Schema::create('ticket_series', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->boolean('is_rectifying')->default(false);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Ajustes del local: una sola fila.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('trade_name');
            $table->string('legal_name');
            $table->string('tax_id', 20);
            $table->string('address')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('ticket_footer')->nullable();
            $table->string('logo_path')->nullable();
            $table->json('enabled_order_types');
            $table->foreignId('default_ticket_series_id')->nullable()->constrained('ticket_series')->nullOnDelete();
            $table->foreignId('rectifying_ticket_series_id')->nullable()->constrained('ticket_series')->nullOnDelete();
            $table->string('half_and_half_pricing', 20)->default('mas_cara');
            $table->string('half_and_half_modifier_pricing', 20)->default('entero');
            $table->unsignedSmallInteger('delivery_fee_vat_rate')->default(1000); // puntos básicos
            $table->unsignedInteger('last_z_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('ticket_series');
    }
};
