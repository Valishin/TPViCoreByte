<?php

namespace App\Filament\Resources\Tickets\Schemas;

use App\Enums\TicketType;
use App\Filament\Components\LinesTree;
use App\Filament\Components\MoneyEntry;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Support\Format;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TicketInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Ticket')->columnSpan(2)->columns(3)->schema([
                    TextEntry::make('number')->label('Número')->weight('bold')
                        ->formatStateUsing(fn (Ticket $record) => $record->fullNumber()),
                    TextEntry::make('issued_at')->label('Emitido')->dateTime('d/m/Y H:i:s'),
                    TextEntry::make('issuedBy.name')->label('Emitido por')->placeholder('—'),
                    TextEntry::make('type')->label('Tipo')->badge(),
                    TextEntry::make('status')->label('Estado')->badge(),
                    TextEntry::make('order_type')->label('Tipo de pedido')->badge()->placeholder('—'),
                    TextEntry::make('order_id')->label('Pedido')->placeholder('—')
                        ->formatStateUsing(fn ($state) => "#{$state}")
                        ->url(fn (Ticket $record) => $record->order_id ? OrderResource::getUrl('view', ['record' => $record->order_id]) : null),
                    TextEntry::make('rectifiedTicket')->label('Rectifica a')
                        ->visible(fn (Ticket $record) => $record->type === TicketType::Rectifying)
                        ->state(fn (Ticket $record) => $record->rectifiedTicket?->fullNumber())
                        ->url(fn (Ticket $record) => $record->rectified_ticket_id ? TicketResource::getUrl('view', ['record' => $record->rectified_ticket_id]) : null),
                    TextEntry::make('rectification_method')->label('Método')->placeholder('—')
                        ->visible(fn (Ticket $record) => $record->type === TicketType::Rectifying),
                ]),
                Section::make('Totales')->columnSpan(1)->schema([
                    MoneyEntry::make('subtotal')->label('Suma de líneas'),
                    MoneyEntry::make('lines_discount')->label('Descuentos e invitaciones de línea')
                        ->visible(fn (Ticket $record) => $record->lines_discount !== 0),
                    MoneyEntry::make('discount_amount')->label('Descuento del pedido')
                        ->visible(fn (Ticket $record) => $record->discount_amount !== 0)
                        ->helperText(fn (Ticket $record) => trim(($record->discount_type?->label() ?? '').' '.($record->discount_reason ?? ''))),
                    MoneyEntry::make('delivery_fee')->label('Gastos de envío')
                        ->visible(fn (Ticket $record) => $record->delivery_fee !== 0),
                    MoneyEntry::make('total')->label('Total')->size('lg')->weight('bold'),
                    TextEntry::make('payment_status')->label('Cobro')->badge(),
                ]),
            ]),
            Section::make('Líneas')->columnSpanFull()->schema([LinesTree::make('lines')]),
            Grid::make(2)->columnSpanFull()->schema([
                Section::make('Desglose de IVA')->schema([
                    RepeatableEntry::make('vat_breakdown')->label('Desglose de IVA')->hiddenLabel()
                        ->table([
                            TableColumn::make('Tipo'),
                            TableColumn::make('Base')->alignment('end'),
                            TableColumn::make('Cuota')->alignment('end'),
                            TableColumn::make('Total')->alignment('end'),
                        ])
                        ->schema([
                            TextEntry::make('rate')->label('Tipo')->formatStateUsing(fn ($state) => Format::percent((int) $state)),
                            TextEntry::make('base')->label('Base')->formatStateUsing(fn ($state) => Format::money((int) $state)),
                            TextEntry::make('tax')->label('Cuota')->formatStateUsing(fn ($state) => Format::money((int) $state)),
                            TextEntry::make('total')->label('Total')->formatStateUsing(fn ($state) => Format::money((int) $state)),
                        ]),
                    Grid::make(2)->schema([
                        MoneyEntry::make('tax_base')->label('Base imponible total'),
                        MoneyEntry::make('tax_amount')->label('Cuota de IVA total'),
                    ]),
                ]),
                Section::make('Pagos')->schema([
                    RepeatableEntry::make('payments')->label('Pagos')->hiddenLabel()
                        ->placeholder('Pendiente de cobro')
                        ->table([
                            TableColumn::make('Fecha'),
                            TableColumn::make('Forma de pago'),
                            TableColumn::make('Importe')->alignment('end'),
                            TableColumn::make('Entregado / cambio')->alignment('end'),
                            TableColumn::make('Cobró'),
                        ])
                        ->schema([
                            TextEntry::make('paid_at')->label('Fecha')->dateTime('d/m H:i'),
                            TextEntry::make('method')->label('Forma de pago')->badge(),
                            MoneyEntry::make('amount')->label('Importe'),
                            TextEntry::make('tendered')->label('Entregado / cambio')->placeholder('—')
                                ->formatStateUsing(fn ($record) => $record->tendered === null ? '—' : Format::money($record->tendered).' / '.Format::money($record->change)),
                            TextEntry::make('user.name')->label('Cobró'),
                        ]),
                ]),
            ]),
            Section::make('Emisor y cliente')->columns(3)->columnSpanFull()->collapsed()->schema([
                TextEntry::make('issuer')->label('Emisor')->html()
                    ->state(fn (Ticket $record) => e($record->issuer['legal_name'] ?? '').'<br>NIF '.e($record->issuer['tax_id'] ?? '').'<br>'.e($record->issuer['address'] ?? '')),
                TextEntry::make('customer')->label('Cliente')->placeholder('—')
                    ->state(fn (Ticket $record) => $record->customer ? trim(($record->customer['name'] ?? '').' · '.($record->customer['phone'] ?? ''), ' ·') : null),
                TextEntry::make('delivery_address')->label('Dirección de entrega')->placeholder('—')
                    ->state(fn (Ticket $record) => OrderInfolist::address($record->delivery_address)),
            ]),
        ]);
    }
}
