<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Copias por SFTP: clave del servidor confirmada por el admin (rclone solo se conecta a ese servidor). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->text('backup_sftp_known_hosts')->nullable());
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('backup_sftp_known_hosts'));
    }
};
