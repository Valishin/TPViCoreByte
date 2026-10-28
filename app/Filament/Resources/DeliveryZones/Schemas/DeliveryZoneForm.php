<?php

namespace App\Filament\Resources\DeliveryZones\Schemas;

use App\Filament\Components\MoneyInput;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeliveryZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Nombre de la zona')->required()->maxLength(255),
                Toggle::make('is_active')->label('Activa')->default(true)->inline(false),
                MoneyInput::make('delivery_fee')->label('Gastos de envío')->default(0)->required(),
                MoneyInput::make('minimum_order')->label('Pedido mínimo')->default(0)->required(),
                TagsInput::make('postal_codes')->label('Códigos postales')->columnSpanFull()
                    ->placeholder('Escribe un código y pulsa Intro')
                    ->splitKeys([',', ' ', 'Tab'])
                    ->nestedRecursiveRules(['regex:/^\d{5}$/']),
            ]),
        ]);
    }
}
