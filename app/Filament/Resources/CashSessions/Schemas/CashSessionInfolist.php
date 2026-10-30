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
                    TextEntry::make('z_full_number')->label('Z nº')->placeholder('Abierta'),
                    TextEntry::make('status')->label('Estado')->badge(),
                    TextEntry::make('openedBy.name')->label('Abrió'),
                    TextEntry::make('opened_at')->label('Apertura')->dateTime('d/m/Y H:i'),
                    TextEntry::make('closedBy.name')->label('Cerró')->placeholder('—'),
                    TextEntry::make('closed_at')->label('Cierre')->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),
                Section::make('Ventas')->schema([
                    // Los canjes de ticket por factura no son ventas nuevas (art. 15.6).
                    TextEntry::make('tickets_count')->label('Tickets y facturas emitidos')
                        ->state(fn (CashSession $record) => $record->tickets()->where('type', '!=', 'completa_canje')->count()),
                    TextEntry::make('sales_total')->label('Total vendido')
                        ->state(fn (CashSession $record) => Format::money((int) $record->tickets()->where('type', '!=', 'completa_canje')->sum('total'))),
                    TextEntry::make('pending_total')->label('Pendiente de cobro')
                        ->state(fn (CashSession $record) => Format::money((int) $record->tickets()->where('payment_status', 'pendiente')->sum('total'))),
                ]),
                Section::make('Cobros por forma de pago')->schema([
                    ...collect(PaymentMethod::cases())->map(fn (PaymentMethod $method) => TextEntry::make("by_{$method->value}")
                        ->label($method->label().' (neto)')
                        ->state(fn (CashSession $record) => Format::money((int) $record->payments()->where('method', $method)->sum('amount')))
                        ->helperText(function (CashSession $record) use ($method) {
                            $refunds = (int) $record->payments()->where('method', $method)->where('amount', '<', 0)->sum('amount');

                            return $refunds ? 'Cobros '.Format::money((int) $record->payments()->where('method', $method)->where('amount', '>', 0)->sum('amount')).' · devoluciones '.Format::money($refunds) : null;
                        }))
                        ->all(),
                    TextEntry::make('card_terminal')->label('Total datáfono (tarjeta + propinas)')->weight('bold')
                        ->state(fn (CashSession $record) => Format::money(
                            (int) $record->payments()->where('method', PaymentMethod::Card)->sum('amount') + (int) $record->payments()->where('method', PaymentMethod::Card)->sum('tip'))),
                ]),
            ]),
            Section::make('Informe Z')->columns(3)->columnSpanFull()
                ->visible(fn (CashSession $record) => filled($record->z_report))
                ->schema([
                    TextEntry::make('z_tickets')->label('Tickets / facturas / rectificativos')
                        ->state(fn (CashSession $record) => ($record->z_report['tickets']['count'] ?? 0).' / '.($record->z_report['invoices']['count'] ?? 0)
                            .' / '.($record->z_report['rectifications']['count'] ?? 0))
                        ->helperText(fn (CashSession $record) => ($record->z_report['exchanges']['count'] ?? 0)
                            ? 'Canjes por factura (no suman): '.$record->z_report['exchanges']['count'] : null),
                    TextEntry::make('z_discounts')->label('Descuentos · invitaciones')
                        ->state(fn (CashSession $record) => Format::money($record->z_report['discounts']['amount'] ?? 0).' · '.Format::money($record->z_report['invitations']['amount'] ?? 0)),
                    TextEntry::make('z_tips')->label('Propinas (fuera de ventas)')
                        ->state(fn (CashSession $record) => Format::money($record->z_report['tips']['total'] ?? 0))
                        ->helperText(fn (CashSession $record) => collect($record->z_report['tips']['by_waiter'] ?? [])->map(fn ($r) => $r['name'].': '.Format::money($r['amount']))->implode(' · ')),
                    TextEntry::make('z_waiters')->label('Por camarero')->columnSpanFull()
                        ->state(fn (CashSession $record) => collect($record->z_report['by_waiter'] ?? [])->map(fn ($r) => "{$r['name']} ({$r['count']}): ".Format::money($r['total']))->implode(' · ') ?: '—'),
                    TextEntry::make('z_types')->label('Por tipo de pedido')->columnSpanFull()
                        ->state(fn (CashSession $record) => collect($record->z_report['by_order_type'] ?? [])->map(fn ($r) => "{$r['label']} ({$r['count']}): ".Format::money($r['total']))->implode(' · ') ?: '—'),
                ]),
            Section::make('Reparto')->columns(3)->columnSpanFull()
                ->visible(fn (CashSession $record) => $record->movements()->exists() || $record->courierSettlements()->exists())
                ->schema([
                    TextEntry::make('settlements')->label('Liquidaciones')->columnSpanFull()
                        ->state(fn (CashSession $record) => $record->courierSettlements()->with('courier')->get()
                            ->map(fn ($s) => "{$s->courier->name}: efectivo ".Format::money($s->cash_collected).', tarjeta '.Format::money($s->card_collected)
                                .($s->difference ? ', diferencia '.Format::money($s->difference) : ''))->implode(' · ') ?: '—'),
                    TextEntry::make('movements')->label('Movimientos de caja (no son ventas)')->columnSpanFull()
                        ->state(fn (CashSession $record) => $record->movements()->with('courier')->get()
                            ->map(fn ($m) => $m->type->label().($m->courier ? " ({$m->courier->name})" : '').': '.Format::money($m->amount))->implode(' · ') ?: '—'),
                    TextEntry::make('z_delivery_pending')->label('Domicilio pendiente de cobro al cerrar')->columnSpanFull()
                        ->visible(fn (CashSession $record) => filled($record->z_report))
                        ->state(fn (CashSession $record) => collect($record->z_report['delivery_pending']['tickets'] ?? [])
                            ->map(fn ($t) => "{$t['number']} ({$t['courier']}): ".Format::money($t['amount']))->implode(' · ') ?: '—'),
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
