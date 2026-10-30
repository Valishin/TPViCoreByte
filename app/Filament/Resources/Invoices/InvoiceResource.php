<?php

namespace App\Filament\Resources\Invoices;

use App\Enums\TicketType;
use App\Filament\Concerns\HasSentenceCaseLabels;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Ticket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Facturas completas (art. 6 RD 1619/2012): directas, canjes y sus
 * rectificativas. Solo consulta; la ficha es la del ticket (mismo documento).
 */
class InvoiceResource extends Resource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'factura';

    protected static ?string $pluralModelLabel = 'facturas';

    protected static ?string $slug = 'facturas';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('type', [TicketType::Invoice, TicketType::InvoiceExchange, TicketType::InvoiceRectifying]);
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }
}
