<?php

namespace App\Filament\Resources\Rooms\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Nombre de la sala')->required()->maxLength(255)->placeholder('Salón, Terraza…'),
                Toggle::make('is_active')->label('Activa')->default(true)->inline(false),
            ]),
        ]);
    }
}
