<?php

use App\Support\DocumentNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeración por año natural: {SERIE}-{AA}-{NNNNNN} y Z-{AA}-{NNNN}
 * (App\Support\DocumentNumber, art. 6.1.a RD 1619/2012).
 *
 * - Contadores por (serie, año) y por año para los Z, en lugar del contador
 *   único de la serie y de settings.last_z_number.
 * - Documento: year y full_number (snapshot); clave única (serie, año, número).
 * - Instalaciones existentes: los documentos ya emitidos conservan su número;
 *   se les pone el año de su fecha de emisión y el número completo, y los
 *   contadores siguen desde el mayor número de cada serie y año. Los tickets
 *   son inmutables (trigger): se desactiva solo durante este relleno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_series_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_series_id')->constrained('ticket_series')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['ticket_series_id', 'year']);
        });
        Schema::create('z_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::table('ticket_series', function (Blueprint $table) {
            $table->unsignedTinyInteger('digits')->default(DocumentNumber::DEFAULT_DIGITS)->after('name');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable()->after('series_code');
            $table->string('full_number', 40)->nullable()->after('number');
        });
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('z_year')->nullable()->after('z_number');
            $table->string('z_full_number', 20)->nullable()->after('z_year');
        });

        $this->backfill();

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable(false)->change();
            $table->string('full_number', 40)->nullable(false)->change();
            $table->dropUnique(['ticket_series_id', 'number']);
            $table->unique(['ticket_series_id', 'year', 'number']);
            $table->index('full_number');
        });
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropUnique(['z_number']);
            $table->unique(['z_year', 'z_number']);
        });
        Schema::table('ticket_series', fn (Blueprint $table) => $table->dropColumn('last_number'));
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('last_z_number'));
    }

    private function backfill(): void
    {
        $tz = config('app.timezone');
        $digits = DB::table('ticket_series')->pluck('digits', 'id');

        DB::unprepared('ALTER TABLE tickets DISABLE TRIGGER tickets_immutable');
        DB::table('tickets')->orderBy('id')->chunkById(500, function ($tickets) use ($tz, $digits) {
            foreach ($tickets as $t) {
                $year = DocumentNumber::yearOf(Carbon::parse($t->issued_at, $tz));
                DB::table('tickets')->where('id', $t->id)->update([
                    'year' => $year,
                    'full_number' => DocumentNumber::format($t->series_code, $year, $t->number, $digits[$t->ticket_series_id] ?? DocumentNumber::DEFAULT_DIGITS),
                ]);
            }
        });
        DB::unprepared('ALTER TABLE tickets ENABLE TRIGGER tickets_immutable');

        $now = now();
        foreach (DB::table('tickets')->selectRaw('ticket_series_id, year, max(number) as last')->groupBy('ticket_series_id', 'year')->get() as $row) {
            DB::table('ticket_series_counters')->insert(['ticket_series_id' => $row->ticket_series_id, 'year' => $row->year, 'last_number' => $row->last, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (DB::table('cash_sessions')->whereNotNull('z_number')->get() as $s) {
            $year = DocumentNumber::yearOf(Carbon::parse($s->closed_at ?? $s->opened_at, $tz));
            DB::table('cash_sessions')->where('id', $s->id)->update(['z_year' => $year, 'z_full_number' => DocumentNumber::format('Z', $year, $s->z_number, 4)]);
        }
        foreach (DB::table('cash_sessions')->whereNotNull('z_year')->selectRaw('z_year, max(z_number) as last')->groupBy('z_year')->get() as $row) {
            DB::table('z_counters')->insert(['year' => $row->z_year, 'last_number' => $row->last, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $table) => $table->unsignedInteger('last_z_number')->default(0));
        Schema::table('ticket_series', fn (Blueprint $table) => $table->unsignedInteger('last_number')->default(0));
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropUnique(['z_year', 'z_number']);
            $table->dropColumn(['z_year', 'z_full_number']);
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['ticket_series_id', 'year', 'number']);
            $table->dropIndex(['full_number']);
        });
        DB::unprepared('ALTER TABLE tickets DISABLE TRIGGER tickets_immutable');
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn(['year', 'full_number']));
        DB::unprepared('ALTER TABLE tickets ENABLE TRIGGER tickets_immutable');
        Schema::table('ticket_series', fn (Blueprint $table) => $table->dropColumn('digits'));
        Schema::dropIfExists('z_counters');
        Schema::dropIfExists('ticket_series_counters');
    }
};
