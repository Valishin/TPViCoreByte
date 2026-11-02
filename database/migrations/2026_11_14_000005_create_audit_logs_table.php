<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log de auditoría: quién, cuándo, qué, valor anterior y nuevo. Solo
 * inserción: el modelo y un trigger de PostgreSQL impiden modificar o borrar
 * filas (como los tickets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 60);
            $table->nullableMorphs('subject');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tpv_audit_log_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'El log de auditoría solo admite inserciones (registro %)', OLD.id;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION tpv_audit_log_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS tpv_audit_log_guard();');
    }
};
