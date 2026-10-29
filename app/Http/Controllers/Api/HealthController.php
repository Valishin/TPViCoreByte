<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Comprueba que la API responde y que la base de datos es accesible.
     */
    public function __invoke(): JsonResponse
    {
        $start = microtime(true);

        try {
            DB::select('select 1');
            $database = [
                'status' => 'ok',
                'driver' => DB::connection()->getDriverName(),
                'latency_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        } catch (Throwable $e) {
            $database = [
                'status' => 'error',
                'error' => config('app.debug') ? $e->getMessage() : 'No se puede conectar a la base de datos',
            ];
        }

        $ok = $database['status'] === 'ok';

        return response()->json([
            'status' => $ok ? 'ok' : 'error',
            'app' => config('app.name'),
            'version' => config('app.version'),
            'commit' => config('app.commit'),
            'time' => now()->toIso8601String(),
            'database' => $database,
        ], $ok ? 200 : 503);
    }
}
