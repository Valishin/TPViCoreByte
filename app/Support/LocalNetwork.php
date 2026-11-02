<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/** Direcciones del propio equipo, de la red local o de Tailscale. */
final class LocalNetwork
{
    public const RANGES = [
        '127.0.0.0/8', '::1/128',                                // el propio equipo
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',          // red local
        'fc00::/7', 'fe80::/10',
        '100.64.0.0/10', 'fd7a:115c:a1e0::/48',                   // Tailscale
    ];

    public static function allows(?string $ip): bool
    {
        return $ip !== null && IpUtils::checkIp($ip, self::RANGES);
    }
}
