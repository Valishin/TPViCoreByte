<?php

namespace App\Filament\Resources\Printers\Tables;

use App\Enums\PrinterStatus;
use App\Enums\PrintJobKind;
use App\Enums\PrintJobStatus;
use App\Jobs\PrintTestPage;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Pos\PrintJobDispatcher;
use App\Services\Printing\PrinterManager;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class PrintersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Impresora')->searchable(),
                TextColumn::make('type')->label('Uso')->badge(),
                TextColumn::make('connection_type')->label('Conexión')->badge(),
                TextColumn::make('host')->label('Dirección')->state(fn (Printer $record) => $record->address()),
                TextColumn::make('columns')->label('Papel')->formatStateUsing(fn (int $state) => $state === 32 ? '58 mm' : '80 mm'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (PrinterStatus $state) => match ($state) {
                        PrinterStatus::Ok => 'success',
                        PrinterStatus::Error => 'danger',
                        PrinterStatus::Unknown => 'gray',
                    })
                    ->tooltip(fn (Printer $record) => $record->last_error),
                IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->recordActions([
                Action::make('printTest')
                    ->label('Imprimir prueba')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(function (Printer $record) {
                        // La petición web no imprime: encola en `printing` (un intento)
                        // y espera el resultado como mucho PRINTER_WEB_WAIT segundos.
                        $printing = app(PrintJobDispatcher::class);
                        try {
                            $job = $printing->dispatch(null, $record, PrintJobKind::Test, PrintTestPage::ticket($record), by: auth()->user(), tries: 1);
                        } catch (Throwable) {
                            // Cola síncrona (tests): el fallo ya está registrado en el envío.
                            $job = PrintJob::query()->where('printer_id', $record->id)->where('kind', PrintJobKind::Test)->latest('id')->first();
                        }
                        $job = $printing->waitFor($job, (float) config('printing.web_wait_seconds'));

                        match ($job->status) {
                            PrintJobStatus::Printed => Notification::make()->success()
                                ->title("Prueba enviada a {$record->name}")
                                ->body(app(PrinterManager::class)->mode() === PrinterManager::MODE_SIMULATED
                                    ? 'Modo simulado: guardada en storage/app/private/prints.'
                                    : "Impresa en {$record->address()}.")
                                ->send(),
                            PrintJobStatus::Failed => Notification::make()->danger()->persistent()
                                ->title("No se ha podido imprimir en {$record->name}")
                                ->body($job->error)
                                ->send(),
                            default => Notification::make()->warning()->persistent()
                                ->title("{$record->name}: sin respuesta todavía")
                                ->body('La prueba está en la cola de impresión. Si en unos segundos no sale, comprueba que el worker `printing` está en marcha y la impresora encendida.')
                                ->send(),
                        };
                    }),
                EditAction::make(),
                DeleteAction::make()->modalDescription('Las categorías y productos que la usaban se quedarán sin impresora.'),
            ]);
    }
}
