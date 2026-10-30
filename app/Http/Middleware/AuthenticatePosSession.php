<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * AuthenticateSession de Sanctum, pero comparando el hash de contraseña con el
 * usuario del guard "pos" (el del TPV). El original usa $request->user(), que
 * en este punto sale del guard por defecto ("web", el de /admin): con /admin
 * abierto por otro usuario en el mismo navegador, cerraba el TPV en cada
 * petición. Se mantiene la protección: si cambia la contraseña del usuario del
 * TPV, su sesión se cierra.
 */
class AuthenticatePosSession extends AuthenticateSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = $request->getUserResolver();
        $request->setUserResolver(fn () => $this->auth->guard('pos')->user());

        return parent::handle($request, function (Request $request) use ($next, $previous) {
            $request->setUserResolver($previous);

            return $next($request);
        });
    }
}
