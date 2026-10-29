<?php

use App\Http\Controllers\Api\DiagnosticsController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Pos\AuthController;
use App\Http\Controllers\Pos\CashController;
use App\Http\Controllers\Pos\CatalogController;
use App\Http\Controllers\Pos\CheckoutController;
use App\Http\Controllers\Pos\CustomerController;
use App\Http\Controllers\Pos\OrderController;
use App\Http\Controllers\Pos\PrintController;
use App\Http\Controllers\Pos\RoomController;
use App\Http\Controllers\Pos\TakeawayOrderController;
use App\Http\Controllers\Pos\TicketController;
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

    // Clientes y zonas (llevar y domicilio)
    Route::get('/customers/lookup', [CustomerController::class, 'lookup'])->name('pos.customers.lookup');
    Route::post('/customers', [CustomerController::class, 'store'])->name('pos.customers.store');
    Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('pos.customers.update');
    Route::post('/customers/{customer}/addresses', [CustomerController::class, 'addAddress'])->name('pos.customers.addresses');
    Route::get('/delivery-zones', [CustomerController::class, 'zones'])->name('pos.delivery-zones');

    // Pedidos para llevar y a domicilio
    Route::get('/takeaway-orders', [TakeawayOrderController::class, 'index'])->name('pos.takeaway.index');
    Route::post('/takeaway-orders', [TakeawayOrderController::class, 'store'])->name('pos.takeaway.store');
    Route::patch('/takeaway-orders/{order}', [TakeawayOrderController::class, 'update'])->name('pos.takeaway.update');
    Route::post('/takeaway-orders/{order}/status', [TakeawayOrderController::class, 'status'])->name('pos.takeaway.status');
    Route::post('/takeaway-orders/{order}/dispatch', [TakeawayOrderController::class, 'dispatch'])->name('pos.takeaway.dispatch');
    Route::post('/takeaway-orders/{order}/cancel', [TakeawayOrderController::class, 'cancel'])->name('pos.takeaway.cancel');
    Route::get('/couriers', [TakeawayOrderController::class, 'couriers'])->name('pos.couriers');
    Route::post('/takeaway-orders/{order}/repeat/{source}', [TakeawayOrderController::class, 'repeat'])->name('pos.takeaway.repeat');

    // Autorización de encargado
    Route::get('/managers', [AuthController::class, 'managers'])->name('pos.managers');
    Route::post('/authorize', [AuthController::class, 'grantAuthorization'])->middleware('throttle:30,1')->name('pos.authorize');

    // Cobro, descuentos y división
    Route::post('/quick-sale', [CheckoutController::class, 'quickSale'])->name('pos.quick-sale');
    Route::post('/orders/{order}/checkout', [CheckoutController::class, 'checkout'])->name('pos.orders.checkout');
    Route::post('/orders/{order}/discount', [CheckoutController::class, 'discountOrder'])->name('pos.orders.discount');
    Route::post('/orders/{order}/split', [CheckoutController::class, 'split'])->name('pos.orders.split');
    Route::post('/order-lines/{line}/discount', [CheckoutController::class, 'discountLine'])->name('pos.lines.discount');
    Route::post('/order-lines/{line}/invite', [CheckoutController::class, 'inviteLine'])->name('pos.lines.invite');

    // Caja y tickets
    Route::get('/cash-session', [CashController::class, 'show'])->name('pos.cash.show');
    Route::post('/cash-session/open', [CashController::class, 'open'])->name('pos.cash.open');
    Route::post('/cash-session/close', [CashController::class, 'close'])->name('pos.cash.close');
    Route::post('/cash-session/print-x', [CashController::class, 'printX'])->name('pos.cash.print-x');
    Route::post('/drawer/open', [CashController::class, 'openDrawer'])->name('pos.drawer.open');
    Route::get('/tickets', [CashController::class, 'tickets'])->name('pos.tickets');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('pos.tickets.show');
    Route::post('/tickets/{ticket}/reprint', [TicketController::class, 'reprint'])->name('pos.tickets.reprint');
    Route::post('/tickets/{ticket}/rectify', [TicketController::class, 'rectify'])->name('pos.tickets.rectify');

    Route::get('/printers', [PrintController::class, 'printers'])->name('pos.printers');
    Route::get('/print-jobs/failed', [PrintController::class, 'failed'])->name('pos.print-jobs.failed');
    Route::post('/print-jobs/{printJob}/retry', [PrintController::class, 'retry'])->name('pos.print-jobs.retry');
    Route::post('/print-jobs/{printJob}/redirect', [PrintController::class, 'redirect'])->name('pos.print-jobs.redirect');
});
