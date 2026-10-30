<?php

namespace App\Filament\Resources\CashSessions\Tables;

use App\Enums\CashSessionStatus;
use App\Filament\Components\DateRangeFilter;
use App\Filament\Components\MoneyColumn;
use App\Filament\Resources\CashSessions\CashSessionResource;
use App\Models\CashSession;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CashSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('z_full_number')->label('Z nº')->placeholder('Abierta')
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('z_year', $direction)->orderBy('z_number', $direction)),
                TextColumn::make('opened_at')->label('Apertura')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('closed_at')->label('Cierre')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('openedBy.name')->label('Abrió'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (CashSessionStatus $state) => $state === CashSessionStatus::Open ? 'warning' : 'success'),
                MoneyColumn::make('expected_cash')->label('Efectivo esperado'),
                MoneyColumn::make('counted_cash')->label('Contado'),
                MoneyColumn::make('difference')->label('Diferencia')
                    ->color(fn ($state) => match (true) {
                        $state === null || $state === 0 => null,
                        $state < 0 => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([DateRangeFilter::make('opened_at', 'Fecha')])
            ->recordActions([ViewAction::make()])
            ->recordUrl(fn (CashSession $record) => CashSessionResource::getUrl('view', ['record' => $record]));
    }
}
