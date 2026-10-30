<?php

use App\Exceptions\AuthorizationRequiredException;
use App\Exceptions\CashSessionRequiredException;
use App\Exceptions\InvoiceRequiredException;
use App\Exceptions\MinimumOrderException;
use App\Exceptions\OpenOrdersException;
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
        // Acceso remoto con HTTPS por Tailscale ("tailscale serve" hace de proxy
        // hacia :80): respetar X-Forwarded-Proto para generar enlaces https.
        // Solo desde redes privadas (el proxy llega por la red de Docker).
        $middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Autorización de encargado / caja: códigos que la SPA reconoce.
        $exceptions->render(function (AuthorizationRequiredException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'authorization_required', 'action' => $e->action->value], 403);
            }
        });
        $exceptions->render(function (CashSessionRequiredException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'cash_session_required'], 409);
            }
        });

        $exceptions->render(function (InvoiceRequiredException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'invoice_required', 'total' => $e->total], 409);
            }
        });
        $exceptions->render(function (MinimumOrderException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(), 'code' => 'minimum_order',
                    'zone' => $e->zone, 'minimum' => $e->minimum, 'amount' => $e->amount, 'missing' => $e->minimum - $e->amount,
                ], 409);
            }
        });
        $exceptions->render(function (OpenOrdersException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'open_orders', 'orders' => $e->orders], 409);
            }
        });

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
