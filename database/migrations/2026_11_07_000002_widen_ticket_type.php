<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // "rectificativa_completa" no cabe en 20 caracteres.
    public function up(): void
    {
        Schema::table('tickets', fn (Blueprint $t) => $t->string('type', 30)->default('simplificada')->change());
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $t) => $t->string('type', 20)->default('simplificada')->change());
    }
};
