<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos para llevar y a domicilio: horas de cada paso, cancelación, pedido
 * mínimo autorizado, entrega fallida, liquidación de repartidores y fondo de
 * cambio (movimientos de caja que no son venta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('takeaway_prep_minutes')->default(15); // preparación (para llevar)
            $table->unsignedSmallInteger('delivery_minutes')->default(35);      // preparación + reparto
            $table->boolean('print_courier_copy')->default(false);              // copia con QR de la dirección
        });

        // Liquidación: una fila por cada "Liquidar" de un repartidor en caja.
        Schema::create('courier_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('settled_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('float_amount')->default(0);   // fondo que devuelve
            $table->unsignedInteger('cash_collected')->default(0); // efectivo cobrado en los repartos
            $table->unsignedInteger('card_collected')->default(0);
            $table->unsignedInteger('other_collected')->default(0);
            $table->integer('expected_cash')->default(0);          // cobrado + fondo
            $table->integer('handed_cash')->nullable();            // lo que entrega de verdad
            $table->integer('difference')->nullable();             // entregado − esperado
            $table->timestamps();
        });

        // Movimientos de efectivo que no son venta (fondo de cambio del repartidor…).
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);                      // fondo_repartidor | devolucion_fondo
            $table->integer('amount');                       // + entra en el cajón, − sale
            $table->foreignId('courier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('courier_settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['cash_session_id', 'type']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('asap')->default(false)->after('promised_at'); // "Lo antes posible (21:30)"

            $table->timestamp('kitchen_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete(); // repartidor o caja

            // Entrega fallida (no está, rechaza, dirección errónea…): rectificativo total.
            $table->string('delivery_failed_reason')->nullable();

            // Pago "ya pagado": método con el que se cobró (se registra al dar salida).
            $table->string('prepaid_method', 20)->nullable()->after('planned_payment_method');

            $table->foreignId('minimum_order_authorized_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();

            $table->foreignId('courier_settlement_id')->nullable()->constrained()->nullOnDelete();

            $table->index(['type', 'delivery_status']);
            $table->index(['courier_id', 'courier_settlement_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['type', 'delivery_status']);
            $table->dropIndex(['courier_id', 'courier_settlement_id']);
            foreach (['delivered_by', 'minimum_order_authorized_by', 'cancelled_by', 'courier_settlement_id'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['asap', 'kitchen_at', 'ready_at', 'dispatched_at', 'delivered_at', 'delivery_failed_reason',
                'prepaid_method', 'cancelled_at', 'cancel_reason']);
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('courier_settlements');
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn(['takeaway_prep_minutes', 'delivery_minutes', 'print_courier_copy']));
    }
};
