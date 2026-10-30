<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VERI*FACTU (RD 1007/2023 y Orden HAC/1177/2024). Ver docs/verifactu/PLAN.md.
 *
 * - fiscal_records: registros de facturación de alta y anulación, tal como se
 *   generaron (XML exacto, huella y encadenamiento). Inmutables: ni se editan
 *   ni se borran (trigger), porque «el registro original no se puede
 *   modificar» (Descripción servicios web 9.1.2). Una única cadena: cada
 *   registro apunta al anterior (único) y solo hay un primer registro (OM 7.a-c).
 * - fiscal_record_states: estado de envío de cada registro (mutable).
 * - verifactu_submissions: cada envío a la AEAT (XML, respuesta, CSV).
 * - verifactu_chain: cabeza de la cadena y control de flujo (una fila).
 * - ticket_annulments: documentos anulados por emitirse por error (RRSIF 11.1).
 * - prechecks: precuentas impresas, conservadas de forma inalterable (LGT
 *   29.2.j; FAQ 11). No generan registro de facturación.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La tabla reservada en la fase 1 nunca se usó: se rehace.
        Schema::dropIfExists('fiscal_records');

        Schema::create('fiscal_records', function (Blueprint $table) {
            $table->id();
            $table->string('record_type', 10);                 // alta | anulacion
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->string('issuer_nif', 9);                    // IDEmisorFactura(Anulada)
            $table->string('invoice_number', 60);               // NumSerieFactura(Anulada)
            $table->string('issue_date', 10);                   // FechaExpedicionFactura dd-mm-aaaa
            $table->string('invoice_type', 2)->nullable();      // TipoFactura (solo alta)
            $table->bigInteger('tax_total')->nullable();        // CuotaTotal en céntimos (solo alta)
            $table->bigInteger('amount_total')->nullable();     // ImporteTotal en céntimos (solo alta)
            $table->boolean('is_first')->default(false);        // PrimerRegistro = S
            $table->foreignId('previous_record_id')->nullable()->unique()->constrained('fiscal_records')->restrictOnDelete();
            $table->string('previous_hash', 64)->nullable();
            $table->string('generated_at', 25);                 // FechaHoraHusoGenRegistro tal cual (ISO 8601 con huso)
            $table->string('hash', 64)->unique();
            $table->boolean('is_correction')->default(false);   // Subsanacion = S
            $table->char('previous_rejection', 1)->nullable();  // RechazoPrevio (N/S/X)
            $table->boolean('without_previous_record')->default(false); // SinRegistroPrevio = S (anulación)
            $table->foreignId('corrects_record_id')->nullable()->constrained('fiscal_records')->restrictOnDelete();
            $table->text('xml');                                // RegistroAlta / RegistroAnulacion exacto
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ticket_id', 'record_type']);
        });
        DB::statement('CREATE UNIQUE INDEX fiscal_records_single_first ON fiscal_records (is_first) WHERE is_first');

        Schema::create('fiscal_record_states', function (Blueprint $table) {
            $table->foreignId('fiscal_record_id')->primary()->constrained()->restrictOnDelete();
            $table->string('status', 25)->default('pendiente'); // pendiente|enviado|correcto|aceptado_con_errores|incorrecto|error_envio
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('incident')->default(false);        // retrasado por incidencia técnica (OM 16.4)
            $table->string('error_code', 10)->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('needs_correction')->default(false);
            $table->foreignId('corrected_by_record_id')->nullable()->constrained('fiscal_records')->nullOnDelete();
            $table->unsignedBigInteger('last_submission_id')->nullable();
            $table->string('aeat_status', 25)->nullable();       // estado conciliado con la consulta
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('verifactu_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 12);
            $table->string('endpoint');
            $table->unsignedSmallInteger('record_count');
            $table->boolean('incident')->default(false);
            $table->longText('request_xml');
            $table->longText('response_xml')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('result', 25)->nullable();            // EstadoEnvio | soap_fault_cliente | soap_fault_servidor | sin_respuesta
            $table->string('csv', 100)->nullable();              // código seguro de verificación
            $table->unsignedInteger('wait_seconds')->nullable(); // TiempoEsperaEnvio
            $table->text('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('verifactu_chain', function (Blueprint $table) {
            $table->id();
            $table->foreignId('last_record_id')->nullable()->constrained('fiscal_records')->restrictOnDelete();
            $table->unsignedInteger('wait_seconds')->default(60); // OM 16.2: inicialmente 60 s
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('incident_since')->nullable();      // primera incidencia sin resolver
            $table->timestamps();
        });
        DB::table('verifactu_chain')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('ticket_annulments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->restrictOnDelete();
            $table->string('reason', 200);
            $table->foreignId('annulled_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('fiscal_record_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('prechecks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('total');
            $table->json('content');                             // líneas impresas, totales y desglose
            $table->timestamp('printed_at');
            $table->timestamp('created_at')->useCurrent();
        });

        // Un ticket puede tener varias F3 (FAQ 27: F3 negativa + F3 nueva al corregir el destinatario).
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['replaces_ticket_id']);
            $table->index('replaces_ticket_id');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('verifactu_enabled')->default(false);
            $table->string('verifactu_environment', 12)->default('pruebas'); // pruebas | produccion
            $table->timestamp('verifactu_started_at')->nullable();
            $table->timestamp('verifactu_first_sent_at')->nullable();        // RRSIF 16.5: obliga hasta el 31/12
            $table->date('verifactu_end_date')->nullable();                 // renuncia: FechaFinVeriFactu (OM 17.3)
            $table->char('vat_regime_key', 2)->default('01');               // ClaveRegimen L8A: 01 general, 20 simplificado
            $table->text('verifactu_certificate')->nullable();              // .p12 cifrado (APP_KEY)
            $table->text('verifactu_certificate_password')->nullable();     // cifrada (APP_KEY)
            $table->string('verifactu_certificate_mode', 15)->default('obligado'); // obligado | representante
            $table->string('verifactu_certificate_kind', 10)->default('personal'); // personal | sello
            $table->string('verifactu_certificate_nif', 20)->nullable();
            $table->string('verifactu_certificate_subject')->nullable();
            $table->timestamp('verifactu_certificate_valid_to')->nullable();
            $table->string('verifactu_representative_name', 120)->nullable(); // Q4: no aplica de momento
            $table->string('verifactu_representative_nif', 9)->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tpv_fiscal_record_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Los registros de facturación son inmutables (registro %)', OLD.id;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER fiscal_records_immutable BEFORE UPDATE OR DELETE ON fiscal_records
                FOR EACH ROW EXECUTE FUNCTION tpv_fiscal_record_guard();
            CREATE TRIGGER ticket_annulments_immutable BEFORE UPDATE OR DELETE ON ticket_annulments
                FOR EACH ROW EXECUTE FUNCTION tpv_fiscal_record_guard();
            CREATE TRIGGER prechecks_immutable BEFORE UPDATE OR DELETE ON prechecks
                FOR EACH ROW EXECUTE FUNCTION tpv_fiscal_record_guard();

            -- Documentos: además de rectificado y canjeado, emitido → anulado (RRSIF 11.1).
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
                    AND NOT (OLD.status = 'emitido' AND NEW.status IN ('rectificado', 'canjeado', 'anulado')) THEN
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
                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'emitido' AND NEW.status IN ('rectificado', 'canjeado')) THEN
                    RAISE EXCEPTION 'Transición de estado no permitida: % -> %', OLD.status, NEW.status;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn([
            'verifactu_enabled', 'verifactu_environment', 'verifactu_started_at', 'verifactu_first_sent_at', 'verifactu_end_date',
            'vat_regime_key', 'verifactu_certificate', 'verifactu_certificate_password', 'verifactu_certificate_mode',
            'verifactu_certificate_kind', 'verifactu_certificate_nif', 'verifactu_certificate_subject', 'verifactu_certificate_valid_to',
            'verifactu_representative_name', 'verifactu_representative_nif',
        ]));
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['replaces_ticket_id']);
            $table->unique('replaces_ticket_id');
        });
        Schema::dropIfExists('prechecks');
        Schema::dropIfExists('ticket_annulments');
        Schema::dropIfExists('verifactu_chain');
        Schema::dropIfExists('verifactu_submissions');
        Schema::dropIfExists('fiscal_record_states');
        Schema::dropIfExists('fiscal_records');
        DB::unprepared('DROP FUNCTION IF EXISTS tpv_fiscal_record_guard() CASCADE;');
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
    }
};
