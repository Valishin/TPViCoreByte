<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                ColorPicker::make('color')->label('Color en el TPV'),
                Select::make('printer_id')
                    ->label('Impresora de destino')
                    ->helperText('Dónde se imprimen los productos de esta categoría (cada producto puede cambiarla).')
                    ->relationship('printer', 'name')
                    ->placeholder('Ninguna'),
                Toggle::make('is_active')->label('Activa')->default(true)->inline(false),
            ]),
        ]);
    }
}
