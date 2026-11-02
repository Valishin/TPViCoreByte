<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copias de seguridad configuradas desde /admin (paso 5 de la puesta en
 * marcha): clave pública age y destino remoto S3 compatible o SFTP. Los
 * secretos van cifrados con la APP_KEY. Vacío = lo del .env (si lo hay).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('backup_age_recipient')->nullable();
            $table->string('backup_remote_type', 10)->nullable();   // s3 | sftp
            $table->string('backup_s3_endpoint')->nullable();
            $table->string('backup_s3_region', 50)->nullable();
            $table->string('backup_s3_bucket')->nullable();
            $table->string('backup_s3_access_key')->nullable();
            $table->text('backup_s3_secret_key')->nullable();      // cifrada
            $table->string('backup_sftp_host')->nullable();
            $table->unsignedInteger('backup_sftp_port')->nullable();
            $table->string('backup_sftp_user')->nullable();
            $table->text('backup_sftp_private_key')->nullable();   // cifrada (la genera la app)
            $table->text('backup_sftp_public_key')->nullable();
            $table->string('backup_path')->nullable();             // carpeta dentro del destino
        });
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn([
            'backup_age_recipient', 'backup_remote_type', 'backup_s3_endpoint', 'backup_s3_region', 'backup_s3_bucket',
            'backup_s3_access_key', 'backup_s3_secret_key', 'backup_sftp_host', 'backup_sftp_port', 'backup_sftp_user',
            'backup_sftp_private_key', 'backup_sftp_public_key', 'backup_path',
        ]));
    }
};
