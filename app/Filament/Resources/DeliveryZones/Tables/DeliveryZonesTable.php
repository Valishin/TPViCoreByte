<?php

namespace App\Filament\Resources\DeliveryZones\Tables;

use App\Filament\Components\MoneyColumn;
use App\Filament\Resources\DeliveryZones\Schemas\DeliveryZoneForm;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeliveryZonesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Zona')->searchable(),
                MoneyColumn::make('delivery_fee')->label('Envío'),
                MoneyColumn::make('minimum_order')->label('Pedido mínimo'),
                TextColumn::make('postal_codes')->label('Códigos postales')->badge()
                    ->state(fn ($record) => DeliveryZoneForm::labels($record)),
                IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('Las direcciones de esa zona se quedarán sin zona asignada.'),
            ]);
    }
}
