<?php

namespace App\Filament\Resources\TicketSeries\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TicketSeriesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('code')->label('Código')->required()->maxLength(10)->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit') // los tickets emitidos llevan este código
                    ->helperText('Prefijo del número de ticket (T-000123). No se puede cambiar después.'),
                TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                Toggle::make('is_rectifying')->label('Serie de rectificativos')->inline(false)->disabledOn('edit'),
                Toggle::make('is_active')->label('Activa')->default(true)->inline(false)
                    ->helperText('Desactívala en lugar de borrarla: las series no se borran.'),
                TextInput::make('last_number')->label('Último número emitido')
                    ->disabled()->dehydrated(false)->visibleOn('edit')
                    ->helperText('Lo asigna el sistema al emitir cada ticket; no se puede modificar.'),
            ]),
        ]);
    }
}
