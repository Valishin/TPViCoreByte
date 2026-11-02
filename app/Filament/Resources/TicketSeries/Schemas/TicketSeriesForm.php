<?php

namespace App\Filament\Resources\TicketSeries\Schemas;

use App\Models\TicketSeries;
use App\Support\DocumentNumber;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                    ->helperText('Prefijo del número (T-26-000123). No se puede cambiar después.'),
                TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                Toggle::make('is_rectifying')->label('Serie de rectificativos')->inline(false)->disabledOn('edit'),
                Toggle::make('is_active')->label('Activa')->default(true)->inline(false)
                    ->helperText('Desactívala en lugar de borrarla: las series no se borran.'),
                // Los documentos ya emitidos guardan su número completo: cambiar las cifras solo afecta a los nuevos.
                TextInput::make('digits')->label('Cifras del número')
                    ->numeric()->integer()->required()->minValue(DocumentNumber::MIN_DIGITS)->maxValue(10)
                    ->default(DocumentNumber::DEFAULT_DIGITS)->live(onBlur: true)
                    ->helperText(fn (Get $get) => 'Ejemplo: '.DocumentNumber::format(strtoupper((string) ($get('code') ?: 'T')), DocumentNumber::yearOf(now()), 1, max(DocumentNumber::MIN_DIGITS, (int) $get('digits') ?: DocumentNumber::DEFAULT_DIGITS))
                        .'. La numeración vuelve a 1 cada año. Los ya emitidos no cambian.'),
                TextInput::make('last_number')->label('Último número emitido este año')
                    ->disabled()->dehydrated(false)->visibleOn('edit')
                    ->formatStateUsing(fn (?TicketSeries $record) => $record?->lastNumber())
                    ->helperText('Lo asigna el sistema al emitir cada documento. Para continuar la numeración de un sistema anterior, «Cambiar primer número» (solo antes del primer documento del año).'),
            ]),
        ]);
    }
}
