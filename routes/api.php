<?php

use App\Http\Controllers\Api\DiagnosticsController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Pos\AuthController;
use App\Http\Controllers\Pos\CatalogController;
use App\Http\Controllers\Pos\OrderController;
use App\Http\Controllers\Pos\PrintController;
use App\Http\Controllers\Pos\RoomController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// Diagnóstico de la página /estado (solo pruebas, sin datos sensibles).
Route::prefix('diagnostico')->middleware('throttle:30,1')->group(function () {
    Route::post('/ping', [DiagnosticsController::class, 'ping'])->name('api.diagnostico.ping');
    Route::post('/imprimir', [DiagnosticsController::class, 'print'])->name('api.diagnostico.imprimir');
});

// --- TPV de sala ---------------------------------------------------------
Route::get('/staff', [AuthController::class, 'staff'])->name('pos.staff');
Route::post('/login-pin', [AuthController::class, 'login'])->name('pos.login');

Route::middleware(['auth:sanctum', 'pos.staff'])->group(function () {
    Route::get('/me', [AuthController::class, 'me'])->name('pos.me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('pos.logout');

    Route::get('/catalog', CatalogController::class)->name('pos.catalog');
    Route::get('/rooms', RoomController::class)->name('pos.rooms');

    Route::post('/tables/{table}/open', [OrderController::class, 'openTable'])->name('pos.tables.open');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('pos.orders.show');
    Route::patch('/orders/{order}', [OrderController::class, 'update'])->name('pos.orders.update');
    Route::post('/orders/{order}/lines', [OrderController::class, 'addLine'])->name('pos.orders.lines');
    Route::post('/orders/{order}/send', [OrderController::class, 'send'])->name('pos.orders.send');
    Route::post('/orders/{order}/move', [OrderController::class, 'move'])->name('pos.orders.move');
    Route::post('/orders/{order}/precheck', [OrderController::class, 'precheck'])->name('pos.orders.precheck');
    Route::patch('/orders/{order}/type', [OrderController::class, 'changeType'])->name('pos.orders.type');
    Route::patch('/order-lines/{line}', [OrderController::class, 'updateLine'])->name('pos.lines.update');
    Route::post('/order-lines/{line}/void', [OrderController::class, 'voidLine'])->name('pos.lines.void');

    Route::get('/printers', [PrintController::class, 'printers'])->name('pos.printers');
    Route::get('/print-jobs/failed', [PrintController::class, 'failed'])->name('pos.print-jobs.failed');
    Route::post('/print-jobs/{printJob}/retry', [PrintController::class, 'retry'])->name('pos.print-jobs.retry');
    Route::post('/print-jobs/{printJob}/redirect', [PrintController::class, 'redirect'])->name('pos.print-jobs.redirect');
});
