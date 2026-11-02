<?php

namespace App\Http\Controllers\Admin;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Salir de /admin sin cerrar la TPV. La caja (guard «pos») y /admin (guard
 * «web») comparten la cookie de sesión: el logout de Filament la invalida
 * entera; aquí solo se cierra el guard de /admin y se renuevan el id de sesión
 * y el token CSRF.
 */
class AdminLogoutController
{
    public function __invoke(Request $request): LogoutResponse
    {
        Filament::auth()->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return app(LogoutResponse::class);
    }
}
