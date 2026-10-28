<?php

namespace App\Filament\Resources\DeliveryZones;

use App\Filament\Resources\DeliveryZones\Pages\CreateDeliveryZone;
use App\Filament\Resources\DeliveryZones\Pages\EditDeliveryZone;
use App\Filament\Resources\DeliveryZones\Pages\ListDeliveryZones;
use App\Models\DeliveryZone;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DeliveryZoneResource extends Resource
{
    protected static ?string $model = DeliveryZone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Clientes y reparto';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'zona de reparto';

    protected static ?string $pluralModelLabel = 'zonas de reparto';

    protected static ?string $slug = 'zonas-reparto';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\DeliveryZoneForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\DeliveryZonesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryZones::route('/'),
            'create' => CreateDeliveryZone::route('/create'),
            'edit' => EditDeliveryZone::route('/{record}/edit'),
        ];
    }
}
