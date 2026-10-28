<?php

namespace App\Filament\Resources\ModifierGroups\Tables;

use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\ModifierGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ModifierGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Grupo')->searchable()->sortable(),
                TextColumn::make('rule')->label('Elección')
                    ->state(fn (ModifierGroup $record) => ProductForm::choiceText($record->min_select, $record->max_select)),
                TextColumn::make('modifiers.name')->label('Opciones')->badge()->limitList(5)->expandableLimitedList(),
                TextColumn::make('products_count')->label('Productos')->counts('products')->alignEnd(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('Se quitará de todos los productos. Los pedidos ya hechos conservan lo elegido.'),
            ]);
    }
}
