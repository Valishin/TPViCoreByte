<?php

namespace App\Http\Controllers\Api;

use App\Events\DiagnosticPing;
use App\Http\Controllers\Controller;
use App\Jobs\PrintTestPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DiagnosticsController extends Controller
{
    /**
     * Emite un evento por Reverb que reciben todas las pantallas en /estado.
     */
    public function ping(Request $request): JsonResponse
    {
        $id = (string) Str::uuid();

        DiagnosticPing::dispatch($id, Str::limit((string) $request->input('origen', 'desconocido'), 120), $request->ip());

        return response()->json(['status' => 'sent', 'id' => $id]);
    }

    /**
     * Encola una página de prueba en la impresora.
     */
    public function print(): JsonResponse
    {
        PrintTestPage::dispatch();

        return response()->json([
            'status' => 'queued',
            'mode' => config('printing.mode'),
        ], 202);
    }
}
