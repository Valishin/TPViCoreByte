<?php

namespace App\Filament\Resources\Categories\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')->label('Nombre')->searchable(),
                TextColumn::make('products_count')->label('Productos')->counts('products')->alignEnd(),
                TextColumn::make('printer.name')->label('Impresora')->placeholder('—'),
                IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->tooltip(fn ($record) => $record->products()->exists() ? 'Tiene productos: muévelos o bórralos antes' : null),
                RestoreAction::make(),
            ]);
    }
}
