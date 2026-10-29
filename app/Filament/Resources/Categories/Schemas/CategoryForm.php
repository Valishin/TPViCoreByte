<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Support\QuickNotes;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
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
            Section::make('Notas rápidas')
                ->description('Botones de nota para cocina en los productos de esta categoría ("Sin hielo", "Poco hecha"…). Arrastra para ordenarlas. Detrás salen las notas generales de Ajustes del local.')
                ->schema([self::quickNotes()]),
        ]);
    }

    /** Lista ordenable de textos cortos (también la usan los Ajustes del local). */
    public static function quickNotes(): Repeater
    {
        return Repeater::make('quick_notes')
            ->hiddenLabel()
            ->simple(TextInput::make('note')->required()->maxLength(QuickNotes::MAX_LENGTH)->placeholder('Sin hielo'))
            ->reorderable()
            ->reorderableWithDragAndDrop()
            ->addActionLabel('Añadir nota')
            ->defaultItems(0)
            ->grid(3);
    }
}
