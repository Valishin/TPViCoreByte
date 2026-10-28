<?php

namespace App\Filament\Resources\Printers;

use App\Filament\Resources\Printers\Pages\CreatePrinter;
use App\Filament\Resources\Printers\Pages\EditPrinter;
use App\Filament\Resources\Printers\Pages\ListPrinters;
use App\Models\Printer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PrinterResource extends Resource
{
    protected static ?string $model = Printer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'impresora';

    protected static ?string $pluralModelLabel = 'impresoras';

    protected static ?string $slug = 'impresoras';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\PrinterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\PrintersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrinters::route('/'),
            'create' => CreatePrinter::route('/create'),
            'edit' => EditPrinter::route('/{record}/edit'),
        ];
    }
}
