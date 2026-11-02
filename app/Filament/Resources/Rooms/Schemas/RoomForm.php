<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\Room;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoomForm
{
    /** Salas habituales que se marcan al crear (las que ya existen salen desactivadas). */
    public const PRESETS = ['Salón', 'Barra', 'Terraza'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                // Crear: marcar las habituales y/o escribir una personalizada (se crean todas a la vez).
                CheckboxList::make('presets')->label('Salas')->visibleOn('create')->columnSpanFull()
                    ->options(array_combine(self::PRESETS, self::PRESETS))->columns(3)
                    ->disableOptionWhen(fn (string $value) => self::exists($value))
                    ->requiredWithout('custom')
                    ->validationMessages(['required_without' => 'Marca alguna sala o escribe una personalizada.']),
                TextInput::make('custom')->label('Otra sala (personalizada)')->visibleOn('create')->maxLength(255)
                    ->placeholder('Comedor, Reservado…'),
                // Editar: el nombre de la sala.
                TextInput::make('name')->label('Nombre de la sala')->hiddenOn('create')->required()->maxLength(255)->placeholder('Salón, Terraza…'),
                Toggle::make('is_active')->label('Activa')->default(true)->inline(false),
            ]),
        ]);
    }

    public static function exists(string $name): bool
    {
        return Room::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->exists();
    }
}
