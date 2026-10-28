<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_series_id')->constrained()->restrictOnDelete();
            $table->string('series_code', 10);
            $table->unsignedBigInteger('number');
            $table->string('type', 20)->default('simplificada');
            $table->string('status', 20)->default('emitido');
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('order_type', 20)->nullable();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');

            // Rectificativos.
            $table->foreignId('rectified_ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->string('rectification_method', 20)->nullable();
            $table->string('rectification_reason')->nullable();

            // Importes (céntimos, IVA incluido salvo tax_base / tax_amount). Negativos en rectificativos.
            $table->integer('subtotal'); // suma de líneas, antes de descuentos
            $table->integer('lines_discount')->default(0);
            $table->string('discount_type', 20)->nullable();
            $table->unsignedInteger('discount_value')->nullable();
            $table->integer('discount_amount')->default(0); // descuento global
            $table->string('discount_reason')->nullable();
            $table->integer('delivery_fee')->default(0);
            $table->integer('tax_base');
            $table->integer('tax_amount');
            $table->integer('total');
            $table->json('vat_breakdown'); // [{rate, base, tax, total}]

            // Snapshots.
            $table->json('issuer'); // datos fiscales del local al emitir
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->json('customer')->nullable();
            $table->json('delivery_address')->nullable();

            $table->string('payment_status', 20)->default('pendiente');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['ticket_series_id', 'number']);
        });

        Schema::create('ticket_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_line_id')->nullable()->constrained('ticket_lines')->restrictOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('name');
            $table->string('variant_name')->nullable();
            $table->integer('quantity');
            $table->integer('unit_price');
            $table->unsignedSmallInteger('vat_rate');
            $table->integer('reference_price')->nullable();
            $table->integer('allocated_amount')->nullable();
            $table->json('modifiers')->nullable(); // [{name, price_delta, quantity}]
            $table->string('discount_type', 20)->nullable();
            $table->unsignedInteger('discount_value')->nullable();
            $table->integer('discount_amount')->default(0);
            $table->boolean('is_invitation')->default(false);
            $table->string('discount_reason')->nullable();
            $table->integer('order_discount_amount')->default(0);
            $table->integer('line_total');
            $table->integer('taxable_amount');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->string('method', 20);
            $table->integer('amount');
            $table->unsignedInteger('tendered')->nullable(); // entregado (efectivo)
            $table->unsignedInteger('change')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        // Reservada para VeriFactu. Sin lógica todavía.
        Schema::create('fiscal_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->string('record_type', 20)->default('alta');
            $table->string('hash', 128)->nullable();
            $table->string('previous_hash', 128)->nullable();
            $table->foreignId('previous_record_id')->nullable()->constrained('fiscal_records')->restrictOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->json('payload')->nullable();
            $table->string('submission_status', 30)->default('pendiente');
            $table->json('aeat_response')->nullable();
            $table->string('aeat_csv', 100)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
        });

        // Segunda barrera de inmutabilidad (la primera está en los modelos):
        // impide borrar tickets/líneas y modificar nada salvo el estado de cobro
        // y la transición emitido → rectificado.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION tpv_ticket_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los tickets son inmutables: no se pueden borrar (ticket %)', OLD.id;
                END IF;
                IF (to_jsonb(NEW) - ARRAY['payment_status', 'paid_at', 'status', 'updated_at'])
                    IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['payment_status', 'paid_at', 'status', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los tickets son inmutables: emite un rectificativo (ticket %)', OLD.id;
                END IF;
                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'emitido' AND NEW.status = 'rectificado') THEN
                    RAISE EXCEPTION 'Transición de estado no permitida: % -> %', OLD.status, NEW.status;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tickets_immutable BEFORE UPDATE OR DELETE ON tickets
                FOR EACH ROW EXECUTE FUNCTION tpv_ticket_guard();

            CREATE FUNCTION tpv_ticket_line_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Las líneas de ticket son inmutables';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ticket_lines_immutable BEFORE UPDATE OR DELETE ON ticket_lines
                FOR EACH ROW EXECUTE FUNCTION tpv_ticket_line_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_records');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('ticket_lines');
        Schema::dropIfExists('tickets');
        DB::unprepared('DROP FUNCTION IF EXISTS tpv_ticket_line_guard(); DROP FUNCTION IF EXISTS tpv_ticket_guard();');
    }
};
