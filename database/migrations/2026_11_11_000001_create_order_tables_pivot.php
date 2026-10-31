<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mesas unidas: un pedido de mesa puede ocupar varias mesas.
 *
 * - order_tables: una fila por mesa del pedido (la principal con is_main).
 *   `orders.table_id` sigue siendo la mesa principal (mover, varias cuentas,
 *   informes por mesa y Z no cambian). Las filas no se borran: left_at marca
 *   cuándo dejó de pertenecer al pedido (separar, cobrar, cancelar, unir) y
 *   left_by quién la separó. Una mesa solo puede estar unida a un grupo a la
 *   vez (índice único parcial sobre las filas no principales activas).
 * - orders: estado «unido» con el pedido que lo absorbió, quién y cuándo.
 * - tickets.table_label: «Mesa 3 + 4» copiado al emitir (snapshot).
 * - settings.print_join_notice: aviso «MESA 4 UNIDA A MESA 3» en cocina y barra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->constrained()->restrictOnDelete();
            $table->boolean('is_main')->default(false);
            $table->foreignId('joined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at');
            $table->foreignId('left_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('left_at')->nullable();

            $table->index(['table_id', 'left_at']);
            $table->index(['order_id', 'left_at']);
        });
        DB::statement('CREATE UNIQUE INDEX order_tables_one_group_per_table ON order_tables (table_id) WHERE left_at IS NULL AND NOT is_main');
        DB::statement('CREATE UNIQUE INDEX order_tables_one_main_per_order ON order_tables (order_id) WHERE left_at IS NULL AND is_main');

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('merged_into_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
        });
        Schema::table('tickets', fn (Blueprint $t) => $t->string('table_label', 120)->nullable());
        Schema::table('settings', fn (Blueprint $t) => $t->boolean('print_join_notice')->default(false));

        // Pedidos de mesa abiertos: su mesa principal.
        DB::statement("INSERT INTO order_tables (order_id, table_id, is_main, joined_by, joined_at)
            SELECT id, table_id, true, opened_by, opened_at FROM orders WHERE table_id IS NOT NULL AND status = 'abierto'");
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('print_join_notice'));
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn('table_label'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_order_id');
            $table->dropConstrainedForeignId('merged_by');
            $table->dropColumn('merged_at');
        });
        Schema::dropIfExists('order_tables');
    }
};
