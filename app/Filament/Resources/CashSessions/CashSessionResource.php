<?php

namespace App\Filament\Resources\CashSessions;

use App\Filament\Resources\CashSessions\Pages\ListCashSessions;
use App\Filament\Resources\CashSessions\Pages\ViewCashSession;
use App\Filament\Resources\CashSessions\Schemas\CashSessionInfolist;
use App\Filament\Resources\CashSessions\Tables\CashSessionsTable;
use App\Models\CashSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Cierres de caja: solo consulta. La caja se abre y cierra desde el TPV. */
class CashSessionResource extends Resource
{
    protected static ?string $model = CashSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'cierre de caja';

    protected static ?string $pluralModelLabel = 'cierres de caja';

    protected static ?string $slug = 'cierres-caja';

    public static function infolist(Schema $schema): Schema
    {
        return CashSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CashSessionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashSessions::route('/'),
            'view' => ViewCashSession::route('/{record}'),
        ];
    }
}
