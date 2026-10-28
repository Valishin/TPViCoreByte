<?php

namespace App\Filament\Resources\Printers\Schemas;

use App\Enums\PrinterType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PrinterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Nombre')->required()->maxLength(255)->placeholder('Cocina'),
                ToggleButtons::make('type')->label('Uso')->options(PrinterType::class)->default(PrinterType::Kitchen)->inline()->required(),
                TextInput::make('host')->label('Dirección IP')->required()->placeholder('192.168.1.200')
                    ->helperText('La impresora debe tener IP fija en el router.'),
                TextInput::make('port')->label('Puerto')->numeric()->integer()->default(9100)->required(),
                Select::make('columns')->label('Ancho del papel')->required()->default(48)
                    ->options([48 => '80 mm (48 caracteres)', 32 => '58 mm (32 caracteres)']),
                TextInput::make('codepage')->label('Página de códigos')->numeric()->integer()->default(16)
                    ->helperText('16 = WPC1252 (acentos, ñ y €) en la mayoría de impresoras. Vacío = automático.'),
                TextInput::make('profile')->label('Perfil')->default('default')->required()
                    ->helperText('Perfil de escpos-php: default, simple, TM-T88V…'),
                Toggle::make('is_active')->label('Activa')->default(true)->inline(false),
            ]),
        ]);
    }
}
