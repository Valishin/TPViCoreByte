<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Factura completa (RD 1619/2012): datos fiscales del destinatario, series F
 * y FR, canje de tickets y precio unitario sin IVA en las líneas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Datos fiscales del destinatario en la ficha del cliente (art. 6.1.c, d, e).
        Schema::table('customers', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');           // nombre y apellidos o razón social
            $table->string('tax_id', 30)->nullable()->after('legal_name');      // normalizado (mayúsculas, sin espacios)
            $table->string('tax_id_type', 10)->nullable()->after('tax_id');     // nif | nie | cif | vat_eu | otro
            $table->string('fiscal_address')->nullable();
            $table->string('fiscal_postal_code', 10)->nullable();
            $table->string('fiscal_city')->nullable();
            $table->string('fiscal_province')->nullable();
            $table->char('fiscal_country', 2)->nullable();                     // ISO 3166-1 alfa-2
            $table->string('billing_email')->nullable();
            // Envío electrónico: solo con consentimiento del destinatario (art. 9.2).
            $table->timestamp('email_invoices_accepted_at')->nullable();
        });
        // Un NIF por cliente (los anonimizados lo tienen a null).
        DB::statement('CREATE UNIQUE INDEX customers_tax_id_unique ON customers (tax_id) WHERE tax_id IS NOT NULL');

        Schema::table('tickets', function (Blueprint $table) {
            $table->json('recipient')->nullable()->after('customer');            // copia de los datos fiscales del destinatario
            $table->date('operation_date')->nullable()->after('issued_at');      // si es distinta de la de expedición (art. 6.1.i)
            $table->foreignId('replaces_ticket_id')->nullable()->after('rectified_ticket_id')
                ->constrained('tickets')->restrictOnDelete();                    // canje (art. 15.6)
            $table->string('rectification_reason_code', 30)->nullable()->after('rectification_reason');
            $table->unique('replaces_ticket_id');                                // un ticket se canjea una sola vez
        });

        // Precio unitario SIN IVA (art. 6.1.f) en diezmilésimas de euro (4 decimales).
        Schema::table('ticket_lines', function (Blueprint $table) {
            $table->bigInteger('unit_price_net')->nullable()->after('unit_price');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('invoice_series_id')->nullable()->constrained('ticket_series')->nullOnDelete();
            $table->foreignId('invoice_rectifying_series_id')->nullable()->constrained('ticket_series')->nullOnDelete();
            // Domicilio del destinatario obligatorio también para particulares (pendiente del asesor).
            $table->boolean('invoice_require_address')->default(true);
        });

        // Instalaciones existentes: series F (completas y canjes) y FR (sus rectificativas).
        if (DB::table('settings')->exists()) {
            $now = now();
            foreach ([['F', 'Facturas', false], ['FR', 'Rectificativas de facturas', true]] as [$code, $name, $rect]) {
                if (! DB::table('ticket_series')->where('code', $code)->exists()) {
                    DB::table('ticket_series')->insert(['code' => $code, 'name' => $name, 'is_rectifying' => $rect, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            DB::table('settings')->update([
                'invoice_series_id' => DB::table('ticket_series')->where('code', 'F')->value('id'),
                'invoice_rectifying_series_id' => DB::table('ticket_series')->where('code', 'FR')->value('id'),
            ]);
        }

        // Inmutabilidad: también se permite emitido → canjeado (el ticket sustituido por una factura).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tpv_ticket_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los tickets son inmutables: no se pueden borrar (ticket %)', OLD.id;
                END IF;
                IF (to_jsonb(NEW) - ARRAY['payment_status', 'paid_at', 'status', 'updated_at'])
                    IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['payment_status', 'paid_at', 'status', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los tickets son inmutables: emite un rectificativo (ticket %)', OLD.id;
                END IF;
                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'emitido' AND NEW.status IN ('rectificado', 'canjeado')) THEN
                    RAISE EXCEPTION 'Transición de estado no permitida: % -> %', OLD.status, NEW.status;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tpv_ticket_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Los tickets son inmutables: no se pueden borrar (ticket %)', OLD.id;
                END IF;
                IF (to_jsonb(NEW) - ARRAY['payment_status', 'paid_at', 'status', 'updated_at'])
                    IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['payment_status', 'paid_at', 'status', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los tickets son inmutables: emite un rectificativo (ticket %)', OLD.id;
                END IF;
                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (OLD.status = 'emitido' AND NEW.status = 'rectificado') THEN
                    RAISE EXCEPTION 'Transición de estado no permitida: % -> %', OLD.status, NEW.status;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        Schema::table('settings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('invoice_series_id');
            $t->dropConstrainedForeignId('invoice_rectifying_series_id');
            $t->dropColumn('invoice_require_address');
        });
        Schema::table('ticket_lines', fn (Blueprint $t) => $t->dropColumn('unit_price_net'));
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropUnique(['replaces_ticket_id']);
            $t->dropConstrainedForeignId('replaces_ticket_id');
            $t->dropColumn(['recipient', 'operation_date', 'rectification_reason_code']);
        });
        DB::statement('DROP INDEX IF EXISTS customers_tax_id_unique');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['legal_name', 'tax_id', 'tax_id_type', 'fiscal_address',
            'fiscal_postal_code', 'fiscal_city', 'fiscal_province', 'fiscal_country', 'billing_email', 'email_invoices_accepted_at']));
    }
};
