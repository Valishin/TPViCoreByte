<?php

namespace App\Http\Middleware;

use App\Support\SetupCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** /admin sin ningún usuario: a «Crear administrador» (si hay código de configuración). */
class RedirectToFirstRun
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SetupCode::available()) {
            return redirect()->route('admin.first-run');
        }

        return $next($request);
    }
}
