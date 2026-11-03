<?php

namespace App\Providers;

use App\Events\Pos\CashChanged;
use App\Events\Pos\FloorChanged;
use App\Events\Pos\PrinterStatusChanged;
use App\Events\Pos\PrintJobUpdated;
use App\Events\Pos\TableStatusChanged;
use App\Http\Controllers\Admin\AdminLogoutController;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\MenuGroup;
use App\Models\MenuGroupItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\ModifierVariantPrice;
use App\Models\Payment;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Room;
use App\Models\Table;
use App\Models\Ticket;
use App\Services\Billing\InvoiceMailer;
use App\Services\Pos\CatalogService;
use App\Services\Printing\PrinterManager;
use App\Support\PosNotifier;
use Filament\Auth\Http\Controllers\LogoutController;
use Filament\Notifications\Livewire\Notifications;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PrinterManager::class, fn () => new PrinterManager(config('printing')));
        // Salir de /admin no cierra la sesión de la TPV (comparten cookie).
        $this->app->bind(LogoutController::class, AdminLogoutController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Avisos de /admin («Guardado»…) abajo a la derecha: arriba tapaban «Ir a la TPV».
        Notifications::alignment(Alignment::End);
        Notifications::verticalAlignment(VerticalAlignment::End);

        // Factura completa emitida: si el cliente aceptó recibirlas por email
        // (art. 9.2) y hay SMTP, se envía sola tras confirmar la transacción.
        Ticket::created(function (Ticket $ticket) {
            if ($ticket->type->isFullInvoice()) {
                DB::afterCommit(fn () => rescue(
                    fn () => app(InvoiceMailer::class)->queueIfAccepted($ticket),
                    report: true,
                ));
            }
        });

        // Cualquier cambio en la carta invalida la caché del TPV y avisa a las pantallas.
        foreach ([Category::class, Product::class, ProductVariant::class, ModifierGroup::class, Modifier::class,
            ModifierVariantPrice::class, MenuGroup::class, MenuGroupItem::class] as $model) {
            $model::saved(fn () => CatalogService::touched());
            $model::deleted(fn () => CatalogService::touched());
        }

        // Estado de mesas, impresoras y envíos → pantallas en tiempo real.
        Table::updated(function (Table $table) {
            if ($table->wasChanged('status')) {
                event(new TableStatusChanged($table));
            }
            // Nombre, sala, posición, forma… (p. ej. desde /admin): el plano entero.
            if (array_diff(array_keys($table->getChanges()), ['status', 'updated_at'])) {
                PosNotifier::later('floor', fn () => new FloorChanged);
            }
        });
        Table::created(fn () => PosNotifier::later('floor', fn () => new FloorChanged));
        Table::deleted(fn () => PosNotifier::later('floor', fn () => new FloorChanged));
        Room::saved(fn () => PosNotifier::later('floor', fn () => new FloorChanged));
        Room::deleted(fn () => PosNotifier::later('floor', fn () => new FloorChanged));

        // Caja (cobros, tickets, movimientos, apertura y cierre): la pantalla Caja de los demás aparatos.
        foreach ([Ticket::class, Payment::class, CashMovement::class, CashSession::class] as $model) {
            $model::saved(fn () => PosNotifier::later('cash', fn () => new CashChanged));
        }
        Printer::updated(function (Printer $printer) {
            if ($printer->wasChanged('status')) {
                event(new PrinterStatusChanged($printer));
            }
        });
        PrintJob::saved(function (PrintJob $job) {
            if ($job->wasRecentlyCreated || $job->wasChanged('status')) {
                event(new PrintJobUpdated($job));
            }
        });
    }
}
