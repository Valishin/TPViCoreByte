<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Verifactu\Verifactu;
use Illuminate\Support\Str;

/**
 * Declaración responsable del sistema (RRSIF 13.2; OM 15.3: disponible dentro
 * del propio sistema de forma legible y accesible) y datos de identificación
 * del sistema y del obligado (OM 2.d).
 */
final class SystemDeclaration
{
    public static function html(): string
    {
        $markdown = (string) @file_get_contents(base_path('docs/verifactu/declaracion-responsable.md'));

        return Str::markdown(str_replace('{{version}}', Verifactu::system()['version'], $markdown), ['html_input' => 'escape']);
    }

    /** @return array<string, mixed> */
    public static function data(): array
    {
        $s = Setting::current();
        $system = Verifactu::system();

        return [
            'obliged' => ['name' => $s->legal_name, 'trade_name' => $s->trade_name, 'nif' => $s->tax_id],
            'system' => [
                'name' => $system['name'], 'id' => $system['id'], 'version' => $system['version'],
                'installation' => $system['installation'], 'producer' => $system['producer_name'], 'producer_nif' => $system['producer_nif'],
                'producer_address' => $system['producer_address'],
                'only_verifactu' => true,
            ],
            'verifactu' => ['active' => Verifactu::active($s), 'environment' => Verifactu::environment($s)],
            'support' => config('tpv.support'),
            'declaration_html' => self::html(),
        ];
    }
}
