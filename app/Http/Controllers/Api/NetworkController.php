<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IP del TPV en la red local, para conectar los móviles (etiqueta fija de la
 * caja). Dentro de Docker no se ve la IP real del equipo: se usa APP_LAN_IP
 * o, si ya se ha entrado por la red, la IP de la petición.
 */
class NetworkController extends Controller
{
    private const PRIVATE = '/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/';

    public function __invoke(Request $request): JsonResponse
    {
        $host = $request->getHost();
        $ip = preg_match(self::PRIVATE, $host) ? $host : (config('app.lan_ip') ?: null);

        return response()->json(['ip' => $ip])->header('Cache-Control', 'no-store');
    }
}
