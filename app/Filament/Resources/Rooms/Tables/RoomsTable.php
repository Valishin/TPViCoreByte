<?php

namespace App\Filament\Resources\Rooms\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->label('Sala')->searchable(),
                TextColumn::make('tables_count')->label('Mesas')->counts('tables')->alignEnd(),
                TextColumn::make('tables_sum_seats')->label('Plazas')->sum('tables', 'seats')->alignEnd(),
                IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('Se borrarán también sus mesas. No se puede si alguna tiene pedidos.'),
            ]);
    }
}
