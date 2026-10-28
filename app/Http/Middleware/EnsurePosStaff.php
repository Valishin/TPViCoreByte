<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Solo personal que usa el TPV de sala (camarero, encargado, admin; activos y con PIN). */
class EnsurePosStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canUsePos()) {
            abort(403, 'Este usuario no puede usar el TPV de sala.');
        }

        return $next($request);
    }
}
