<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Alarmas de VERI*FACTU que se muestran en caja y en /admin hasta que se
 * resuelven (OM 6.f y 7.j para la cadena). Por tipo:
 *  - chain: integridad o trazabilidad de la cadena (la apaga tpv:verifactu:verify sin problemas);
 *  - send: envío rechazado por la AEAT o registro duplicado anulado;
 *  - reconcile: discrepancias con la consulta a la AEAT.
 */
final class VerifactuAlarm
{
    public const CHAIN = 'chain';

    public const SEND = 'send';

    public const RECONCILE = 'reconcile';

    private const KEY = 'verifactu.alarm.';

    public static function raise(string $message, string $kind = self::SEND): void
    {
        Log::error("VERI*FACTU ({$kind}): {$message}");
        Cache::forever(self::KEY.$kind, ['kind' => $kind, 'message' => $message, 'at' => now()->toIso8601String()]);
    }

    /** La alarma más reciente (o la de un tipo). */
    public static function current(?string $kind = null): ?array
    {
        if ($kind) {
            return Cache::get(self::KEY.$kind);
        }

        return collect(self::all())->sortByDesc('at')->first();
    }

    /** @return list<array{kind: string, message: string, at: string}> */
    public static function all(): array
    {
        return array_values(array_filter(array_map(fn ($k) => Cache::get(self::KEY.$k), [self::CHAIN, self::SEND, self::RECONCILE])));
    }

    public static function clear(?string $kind = null): void
    {
        foreach ($kind ? [$kind] : [self::CHAIN, self::SEND, self::RECONCILE] as $k) {
            Cache::forget(self::KEY.$k);
        }
    }
}
