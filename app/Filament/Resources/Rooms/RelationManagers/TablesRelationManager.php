<?php

namespace App\Filament\Resources\Rooms\RelationManagers;

use App\Enums\TableShape;
use App\Enums\TableStatus;
use App\Models\Table as DiningTable;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Mesas de la sala. El estado (libre/ocupada) NO se edita aquí: lo gestiona
 * el TPV (OrderService). "Activa" pone la mesa fuera de servicio.
 */
class TablesRelationManager extends RelationManager
{
    protected static string $relationship = 'tables';

    protected static ?string $title = 'Mesas';

    protected static ?string $modelLabel = 'mesa';

    protected static ?string $pluralModelLabel = 'mesas';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nombre o número')->required()->maxLength(50)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('room_id', $this->getOwnerRecord()->id)),
            TextInput::make('seats')->label('Plazas')->numeric()->integer()->minValue(1)->default(4)->required(),
            ToggleButtons::make('shape')->label('Forma')->options(TableShape::class)->default(TableShape::Square)->inline()->required(),
            Toggle::make('is_active')->label('Activa')->default(true)->inline(false)
                ->helperText('Desactívala para ponerla fuera de servicio.')
                ->rule(fn (?DiningTable $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                    if ($record && ! $value && $record->openOrders()->exists()) {
                        $fail(str_replace(':name', $record->name, DiningTable::OPEN_ORDER_MESSAGE));
                    }
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Mesa')->searchable()->sortable(),
                TextColumn::make('seats')->label('Plazas')->alignEnd(),
                TextColumn::make('shape')->label('Forma'),
                TextColumn::make('status')->label('Ahora')->badge()
                    ->color(fn (TableStatus $state) => match ($state) {
                        TableStatus::Free => 'success',
                        TableStatus::Occupied => 'warning',
                        TableStatus::BillRequested => 'info',
                        TableStatus::OutOfService => 'gray',
                    }),
                IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (DiningTable $record) => $record->orders()->exists())
                    ->tooltip(fn (DiningTable $record) => $record->orders()->exists() ? 'Tiene pedidos: desactívala en lugar de borrarla' : null),
            ]);
    }
}
