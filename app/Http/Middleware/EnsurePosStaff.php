<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quién entra en cada parte del TPV:
 *   pos.staff          personal de sala (camarero, encargado, admin; activos y con PIN)
 *   pos.staff:courier  solo repartidores ("Mis repartos")
 *   pos.staff:any      cualquiera de los dos (sesión, autorización de encargado)
 */
class EnsurePosStaff
{
    public function handle(Request $request, Closure $next, string $who = 'staff'): Response
    {
        $user = $request->user();
        $allowed = match ($who) {
            'courier' => $user?->canUseCourierApp(),
            'any' => $user?->canSignInPos(),
            default => $user?->canUsePos(),
        };
        if (! $allowed) {
            abort(403, $who === 'courier' ? 'Solo para repartidores.' : 'Este usuario no puede usar el TPV de sala.');
        }

        return $next($request);
    }
}
