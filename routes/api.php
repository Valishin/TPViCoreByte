<?php

use App\Http\Controllers\Api\DiagnosticsController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Diagnóstico de la página /estado (solo pruebas, sin datos sensibles).
Route::prefix('diagnostico')->middleware('throttle:30,1')->group(function () {
    Route::post('/ping', [DiagnosticsController::class, 'ping'])->name('api.diagnostico.ping');
    Route::post('/imprimir', [DiagnosticsController::class, 'print'])->name('api.diagnostico.imprimir');
});
