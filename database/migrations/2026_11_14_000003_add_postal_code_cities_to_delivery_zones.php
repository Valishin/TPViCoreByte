<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Municipio de cada código postal de la zona de reparto ({"17220": "Sant Feliu
 * de Guíxols"}): se rellena en /admin y sale en la dirección y el ticket de
 * domicilio. Las zonas que ya existen toman el municipio más usado en las
 * direcciones guardadas con ese código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_zones', fn (Blueprint $t) => $t->json('postal_code_cities')->nullable()->after('postal_codes'));

        foreach (DB::table('delivery_zones')->get(['id', 'postal_codes']) as $zone) {
            $cities = [];
            foreach (json_decode((string) $zone->postal_codes, true) ?: [] as $cp) {
                $city = DB::table('customer_addresses')->where('postal_code', (string) $cp)->whereNotNull('city')->where('city', '<>', '')
                    ->selectRaw('city, count(*) as uses')->groupBy('city')->orderByDesc('uses')->orderBy('city')->value('city');
                if ($city) {
                    $cities[(string) $cp] = $city;
                }
            }
            if ($cities) {
                DB::table('delivery_zones')->where('id', $zone->id)->update(['postal_code_cities' => json_encode($cities, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('delivery_zones', fn (Blueprint $t) => $t->dropColumn('postal_code_cities'));
    }
};
