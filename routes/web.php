<?php

use App\Http\Controllers\Admin\FirstRunController;
use Illuminate\Support\Facades\Route;

/*
| Ruta comodín de la SPA (Vue Router en modo history).
|
| Todo lo que no sea una ruta del backend devuelve el contenedor de la SPA.
| Las rutas excluidas las registran Laravel o los paquetes (Filament, Livewire,
| Sanctum, broadcasting, /up, assets compilados y storage).
*/
// Primer acceso a /admin: crear el administrador con el código de install.sh.
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/admin/primer-acceso', [FirstRunController::class, 'show'])->name('admin.first-run');
    Route::post('/admin/primer-acceso', [FirstRunController::class, 'store'])->name('admin.first-run.store');
});

Route::view('/{any?}', 'app')
    ->where('any', '^(?!(admin|api|livewire|filament|broadcasting|sanctum|storage|build|up|css|js|fonts|favicon\.ico|robots\.txt)(/|$)).*$')
    ->name('spa');
