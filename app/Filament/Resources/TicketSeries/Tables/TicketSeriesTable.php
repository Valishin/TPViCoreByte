<?php

namespace App\Filament\Resources\TicketSeries\Tables;

use App\Models\Setting;
use App\Models\TicketSeries;
use App\Support\DocumentNumber;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TicketSeriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label('Código')->weight('bold'),
                TextColumn::make('name')->label('Nombre')
                    ->description(function (TicketSeries $record) {
                        $settings = Setting::query()->first();

                        return match ($record->id) {
                            $settings?->default_ticket_series_id => 'Serie por defecto',
                            $settings?->rectifying_ticket_series_id => 'Serie de rectificativos por defecto',
                            default => null,
                        };
                    }),
                IconColumn::make('is_rectifying')->label('Rectificativos')->boolean(),
                TextColumn::make('digits')->label('Formato')
                    ->state(fn (TicketSeries $record) => DocumentNumber::format($record->code, DocumentNumber::yearOf(now()), 1, $record->digits)),
                TextColumn::make('last_number')->label('Último nº '.DocumentNumber::yearOf(now()))
                    ->state(fn (TicketSeries $record) => $record->lastNumber())->numeric()->alignEnd(),
                IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
