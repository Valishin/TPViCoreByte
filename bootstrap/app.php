<?php

use App\Exceptions\TableUnavailableException;
use App\Http\Middleware\EnsurePosStaff;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Autorización de canales privados con la sesión del TPV (Sanctum).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum en modo SPA: las peticiones a /api desde los dominios de
        // SANCTUM_STATEFUL_DOMAINS se autentican con la cookie de sesión.
        $middleware->statefulApi();
        $middleware->alias(['pos.staff' => EnsurePosStaff::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Reglas de negocio de los servicios de la app → JSON legible en la API.
        $exceptions->render(function (TableUnavailableException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 409);
            }
        });
        $exceptions->render(function (LogicException $e, Request $request) {
            if ($request->is('api/*') && str_starts_with($e->getFile(), app_path())) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })->create();
