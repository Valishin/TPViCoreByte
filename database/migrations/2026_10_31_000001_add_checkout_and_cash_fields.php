<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de autorizaciones de encargado (quién pidió, quién autorizó, para qué).
        Schema::create('authorizations', function (Blueprint $table) {
            $table->id();
            $table->string('action', 30);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->nullableMorphs('subject');
            $table->string('reason')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('discount_limit_bp')->default(1000); // 10 %
            $table->boolean('print_ticket_on_checkout')->default(true);
        });

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->json('opening_breakdown')->nullable();
            $table->json('counted_breakdown')->nullable();
            $table->json('z_report')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('tip')->default(0)->after('change'); // no entra en el ticket ni en el IVA
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique(); // un intento de cobro = un ticket
            $table->json('rectified_amounts')->nullable(); // por sustitución: base/cuota/total del original
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->after('ticket_id')->constrained()->nullOnDelete();
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignId('split_from_id')->nullable()->constrained('order_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('split_from_id'));
        Schema::table('print_jobs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cash_session_id');
            $t->dropConstrainedForeignId('ticket_id');
        });
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn(['idempotency_key', 'rectified_amounts']));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('tip'));
        Schema::table('cash_sessions', fn (Blueprint $t) => $t->dropColumn(['opening_breakdown', 'counted_breakdown', 'z_report']));
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn(['discount_limit_bp', 'print_ticket_on_checkout']));
        Schema::dropIfExists('authorizations');
    }
};
