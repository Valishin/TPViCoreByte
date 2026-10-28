<?php

namespace App\Filament\Resources\TicketSeries;

use App\Filament\Resources\TicketSeries\Pages\CreateTicketSeries;
use App\Filament\Resources\TicketSeries\Pages\EditTicketSeries;
use App\Filament\Resources\TicketSeries\Pages\ListTicketSeries;
use App\Models\TicketSeries;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TicketSeriesResource extends Resource
{
    protected static ?string $model = TicketSeries::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'serie de tickets';

    protected static ?string $pluralModelLabel = 'series de tickets';

    protected static ?string $slug = 'series';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\TicketSeriesForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\TicketSeriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTicketSeries::route('/'),
            'create' => CreateTicketSeries::route('/create'),
            'edit' => EditTicketSeries::route('/{record}/edit'),
        ];
    }
}
