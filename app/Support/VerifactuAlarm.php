<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Alarma de integridad o trazabilidad de la cadena de registros (OM 6.f y 7.j):
 * se muestra en caja y en /admin y no se quita hasta que se resuelve
 * (`tpv:verifactu:verify` sin problemas la apaga).
 */
final class VerifactuAlarm
{
    private const KEY = 'verifactu.alarm';

    public static function raise(string $message): void
    {
        Log::error('VERI*FACTU: '.$message);
        Cache::forever(self::KEY, ['message' => $message, 'at' => now()->toIso8601String()]);
    }

    public static function current(): ?array
    {
        return Cache::get(self::KEY);
    }

    public static function clear(): void
    {
        Cache::forget(self::KEY);
    }
}
