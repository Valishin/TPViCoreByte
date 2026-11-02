<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Los eventos de Eloquent que terminan en «-ing» (saving, creating,
 * updating, deleting…) se disparan con `until`: el primer listener que
 * devuelve algo distinto de null corta los siguientes, y un guard posterior
 * no llegaría a ejecutarse. Por eso estos listeners no pueden ser funciones
 * flecha que devuelvan un valor (sí las que solo lanzan una excepción).
 */
class ModelListenersTest extends TestCase
{
    public function test_halting_model_listeners_never_return_a_value(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $pattern = '/::(saving|creating|updating|deleting|restoring|forceDeleting|replicating|trashed)\(\s*(static\s+)?fn\s*\([^)]*\)\s*(:\s*\w+\s*)?=>(?!\s*throw\b)/';
            if (preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$match, $offset]) {
                    $offenders[] = basename($file->getPathname()).':'.(substr_count(substr($code, 0, $offset), "\n") + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Listener «-ing» con función flecha que devuelve un valor: usa function (...): void { … }');
    }
}
