<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('z_number')->nullable()->unique(); // se asigna al cerrar
            $table->string('status', 20)->default('abierta');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->unsignedInteger('opening_float')->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->integer('expected_cash')->nullable();
            $table->integer('counted_cash')->nullable();
            $table->integer('difference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('status', 20)->default('abierto');
            $table->foreignId('table_id')->nullable()->constrained()->restrictOnDelete(); // solo tipo mesa
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('guests')->nullable();

            // Cliente y snapshot de contacto / dirección (domicilio y para llevar).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_address_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->json('delivery_address')->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('promised_at')->nullable();
            $table->foreignId('courier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_status', 20)->nullable();
            $table->string('planned_payment_method', 20)->nullable();
            $table->unsignedInteger('cash_change_for')->nullable(); // "cambio para"
            $table->unsignedInteger('delivery_fee')->default(0);

            // Descuento global (se reparte entre las líneas para el desglose de IVA).
            $table->string('discount_type', 20)->nullable();
            $table->unsignedInteger('discount_value')->nullable();
            $table->unsignedInteger('discount_amount')->default(0);
            $table->string('discount_reason')->nullable();
            $table->foreignId('discounted_by')->nullable()->constrained('users')->nullOnDelete();

            // Caché de totales (los recalcula OrderTotalsService).
            $table->unsignedInteger('subtotal')->default(0); // líneas tras sus descuentos
            $table->unsignedInteger('total')->default(0);

            $table->text('notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_line_id')->nullable()->constrained('order_lines')->cascadeOnDelete();
            $table->string('kind', 20)->default('normal');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot del producto en el momento de pedir.
            $table->string('name');
            $table->string('variant_name')->nullable();
            $table->string('variant_code', 30)->nullable();
            $table->unsignedInteger('unit_price')->default(0); // en hijas de menú: el suplemento
            $table->unsignedSmallInteger('vat_rate'); // tipo realmente aplicado
            $table->unsignedInteger('reference_price')->nullable(); // precio suelto (hijas)
            $table->unsignedInteger('allocated_amount')->nullable(); // parte del precio del padre, por unidad del padre (hijas)
            $table->unsignedSmallInteger('quantity')->default(1); // en hijas: por unidad del padre

            // Descuento / invitación de la línea.
            $table->string('discount_type', 20)->nullable();
            $table->unsignedInteger('discount_value')->nullable();
            $table->unsignedInteger('discount_amount')->default(0);
            $table->boolean('is_invitation')->default(false);
            $table->string('discount_reason')->nullable();
            $table->foreignId('discounted_by')->nullable()->constrained('users')->nullOnDelete();

            // Calculados por OrderTotalsService.
            $table->unsignedInteger('line_total')->default(0); // bruto antes de descuentos
            $table->unsignedInteger('order_discount_amount')->default(0); // parte del descuento global
            $table->unsignedInteger('taxable_amount')->default(0); // importe (IVA incl.) que tributa a vat_rate en ESTA línea

            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('course')->nullable(); // 1 primeros, 2 segundos, 3 postres
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('fired_at')->nullable(); // "marchar"
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('order_line_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->integer('price_delta')->default(0); // precio aplicado (ya según tamaño)
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_line_modifiers');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cash_sessions');
    }
};
