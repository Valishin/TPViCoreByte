<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('color', 9)->nullable()->after('role');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('auto_lock_minutes_pos')->default(2);    // 0 = nunca
            $table->unsignedSmallInteger('auto_lock_minutes_mobile')->default(0); // 0 = nunca
            $table->boolean('lock_pos_after_send')->default(false);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('send_count')->default(0);
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->unsignedSmallInteger('send_number')->nullable()->after('sent_at');
        });

        Schema::table('printers', function (Blueprint $table) {
            $table->string('status', 20)->default('desconocido'); // ok | error | desconocido
            $table->text('last_error')->nullable();
            $table->timestamp('last_status_at')->nullable();
        });

        // Cada envío a una impresora (comanda, anulación o cuenta).
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('original_printer_id')->nullable()->constrained('printers')->nullOnDelete();
            $table->string('kind', 20); // comanda | anulacion | cuenta
            $table->unsignedSmallInteger('send_number')->nullable();
            $table->json('line_ids')->nullable();
            $table->json('content'); // operaciones del Ticket: se reimprime exactamente lo mismo
            $table->string('status', 20)->default('pendiente'); // pendiente | impreso | fallido
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::table('printers', fn (Blueprint $t) => $t->dropColumn(['status', 'last_error', 'last_status_at']));
        Schema::table('order_lines', fn (Blueprint $t) => $t->dropColumn('send_number'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('send_count'));
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn(['auto_lock_minutes_pos', 'auto_lock_minutes_mobile', 'lock_pos_after_send']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('color'));
    }
};
