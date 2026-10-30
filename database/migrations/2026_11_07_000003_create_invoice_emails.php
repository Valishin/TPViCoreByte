<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Correo saliente del local (para enviar las facturas en PDF).
        Schema::table('settings', function (Blueprint $table) {
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 10)->nullable();   // tls (STARTTLS) | ssl | none
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();           // cifrada con APP_KEY
            $table->string('mail_from_address')->nullable();
            $table->string('mail_from_name')->nullable();
        });

        // Estado de cada envío (la factura es inmutable: el estado va aparte).
        Schema::create('invoice_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->string('to');
            $table->string('status', 20)->default('pendiente');  // pendiente | enviado | error
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_emails');
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn(['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
            'smtp_password', 'mail_from_address', 'mail_from_name']));
    }
};
