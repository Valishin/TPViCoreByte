<?php

namespace App\Filament\Resources\Printers\Tables;

use App\Jobs\PrintTestPage;
use App\Models\Printer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Bus;
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
                TextColumn::make('host')->label('Dirección')->formatStateUsing(fn (Printer $record) => "{$record->host}:{$record->port}"),
                TextColumn::make('columns')->label('Papel')->formatStateUsing(fn (int $state) => $state === 32 ? '58 mm' : '80 mm'),
                IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->recordActions([
                Action::make('printTest')
                    ->label('Imprimir prueba')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(function (Printer $record) {
                        try {
                            // Síncrono: el resultado (o el error) se ve al momento.
                            $result = Bus::dispatchNow(new PrintTestPage($record->id));
                        } catch (Throwable $e) {
                            Notification::make()->danger()->persistent()
                                ->title("No se ha podido imprimir en {$record->name}")
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title("Prueba enviada a {$record->name}")
                            ->body($result['mode'] === 'simulated'
                                ? 'Modo simulado: guardada en '.basename($result['file']).' (storage/app/private/prints).'
                                : "Enviada a {$result['target']}.")
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make()->modalDescription('Las categorías y productos que la usaban se quedarán sin impresora.'),
            ]);
    }
}
