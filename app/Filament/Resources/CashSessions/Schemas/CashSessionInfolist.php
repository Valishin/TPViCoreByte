<?php

namespace App\Filament\Resources\CashSessions\Schemas;

use App\Enums\PaymentMethod;
use App\Filament\Components\MoneyEntry;
use App\Models\CashSession;
use App\Support\Format;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Detalle del Z: apertura/cierre, ventas, cobros por forma de pago y arqueo. */
class CashSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Caja')->columns(2)->schema([
                    TextEntry::make('z_number')->label('Z nº')->placeholder('Abierta'),
                    TextEntry::make('status')->label('Estado')->badge(),
                    TextEntry::make('openedBy.name')->label('Abrió'),
                    TextEntry::make('opened_at')->label('Apertura')->dateTime('d/m/Y H:i'),
                    TextEntry::make('closedBy.name')->label('Cerró')->placeholder('—'),
                    TextEntry::make('closed_at')->label('Cierre')->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),
                Section::make('Ventas')->schema([
                    TextEntry::make('tickets_count')->label('Tickets emitidos')
                        ->state(fn (CashSession $record) => $record->tickets()->count()),
                    TextEntry::make('sales_total')->label('Total vendido')
                        ->state(fn (CashSession $record) => Format::money((int) $record->tickets()->sum('total'))),
                    TextEntry::make('pending_total')->label('Pendiente de cobro')
                        ->state(fn (CashSession $record) => Format::money((int) $record->tickets()->where('payment_status', 'pendiente')->sum('total'))),
                ]),
                Section::make('Cobros por forma de pago')->schema(
                    collect(PaymentMethod::cases())->map(fn (PaymentMethod $method) => TextEntry::make("by_{$method->value}")
                        ->label($method->label())
                        ->state(fn (CashSession $record) => Format::money((int) $record->payments()->where('method', $method)->sum('amount'))))
                        ->all()
                ),
            ]),
            Section::make('Arqueo de efectivo')->columns(5)->columnSpanFull()->schema([
                MoneyEntry::make('opening_float')->label('Fondo inicial'),
                TextEntry::make('cash_in')->label('Cobrado en efectivo')
                    ->state(fn (CashSession $record) => Format::money((int) $record->payments()->where('method', PaymentMethod::Cash)->sum('amount'))),
                MoneyEntry::make('expected_cash')->label('Efectivo esperado'),
                MoneyEntry::make('counted_cash')->label('Contado'),
                MoneyEntry::make('difference')->label('Diferencia')->weight('bold')
                    ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'warning' : 'success')),
                TextEntry::make('notes')->label('Notas')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }
}
