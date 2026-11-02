<?php

namespace App\Http\Middleware;

use App\Support\FirstRun;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** /admin sin ningún usuario: a «Crear administrador». */
class RedirectToFirstRun
{
    public function handle(Request $request, Closure $next): Response
    {
        if (FirstRun::available()) {
            return redirect()->route('admin.first-run');
        }

        return $next($request);
    }
}
