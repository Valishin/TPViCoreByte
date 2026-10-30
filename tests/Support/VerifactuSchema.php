<?php

namespace Tests\Support;

use DOMDocument;

/**
 * Valida XML contra los XSD oficiales de la AEAT (docs/verifactu/oficial/xsd).
 * SuministroInformacion.xsd importa xmldsig por URL: se usa la copia local
 * para no depender de la red.
 */
final class VerifactuSchema
{
    private static ?string $dir = null;

    /** @return list<string> errores (vacío = válido) */
    public static function errors(string $xml, string $schema = 'SuministroLR.xsd'): array
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc->schemaValidate(self::dir().'/'.$schema);
        $errors = array_map(fn ($e) => trim($e->message).' (línea '.$e->line.')', libxml_get_errors());
        libxml_clear_errors();

        return $errors;
    }

    private static function dir(): string
    {
        if (self::$dir) {
            return self::$dir;
        }
        $source = base_path('docs/verifactu/oficial/xsd');
        $dir = sys_get_temp_dir().'/verifactu-xsd-'.getmypid();
        @mkdir($dir);
        foreach (glob($source.'/*.xsd') as $file) {
            $content = str_replace('http://www.w3.org/TR/xmldsig-core/xmldsig-core-schema.xsd', 'xmldsig-core-schema.xsd', file_get_contents($file));
            file_put_contents($dir.'/'.basename($file), $content);
        }

        return self::$dir = $dir;
    }
}
