<?php

namespace App\Filament\Resources\Rooms;

use App\Filament\Concerns\HasSentenceCaseLabels;
use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\RelationManagers\TablesRelationManager;
use App\Models\Room;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RoomResource extends Resource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = Room::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Sala';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'sala';

    protected static ?string $pluralModelLabel = 'salas';

    protected static ?string $slug = 'salas';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\RoomForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\RoomsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TablesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRooms::route('/'),
            'create' => CreateRoom::route('/create'),
            'edit' => EditRoom::route('/{record}/edit'),
        ];
    }
}
