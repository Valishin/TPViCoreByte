<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->string('connection', 10)->default('red')->after('type'); // red | usb
            $table->string('device_path')->nullable()->after('port');        // /dev/impresora-ticket
        });
    }

    public function down(): void
    {
        Schema::table('printers', fn (Blueprint $t) => $t->dropColumn(['connection', 'device_path']));
    }
};
