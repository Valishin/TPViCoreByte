<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('camarero')->after('email');
            $table->string('pin')->nullable()->after('password'); // hash de un PIN de 4 dígitos
            $table->boolean('is_active')->default(true)->after('pin');
            $table->string('email')->nullable()->change(); // camareros y repartidores pueden no tener email
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'pin', 'is_active']);
        });
    }
};
