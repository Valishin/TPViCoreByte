<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderType;
use App\Filament\Components\LinesTree;
use App\Filament\Components\MoneyEntry;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Order;
use App\Models\User;
use App\Support\Format;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Pedido')->columnSpan(2)->columns(3)->schema([
                    TextEntry::make('type')->label('Tipo')->badge(),
                    TextEntry::make('status')->label('Estado')->badge(),
                    TextEntry::make('table')->label('Mesa')->placeholder('—')
                        ->state(fn (Order $record) => $record->table ? "{$record->table->room->name} · {$record->tableLabel()}" : null),
                    TextEntry::make('mergedInto.id')->label('Unido al pedido')->placeholder('—')
                        ->visible(fn (Order $record) => $record->merged_into_order_id !== null)
                        ->state(fn (Order $record) => "#{$record->merged_into_order_id} · ".$record->merged_at?->format('d/m H:i').' · '.User::query()->find($record->merged_by)?->name),
                    TextEntry::make('cancelled_table')->label('Cancelado')->placeholder('—')->columnSpan(2)
                        ->visible(fn (Order $record) => $record->table_id && $record->cancelled_at)
                        ->state(fn (Order $record) => $record->cancelled_at?->format('d/m H:i').' · '.$record->cancelledBy?->name.' · '.$record->cancel_reason),
                    TextEntry::make('openedBy.name')->label('Abierto por'),
                    TextEntry::make('opened_at')->label('Abierto')->dateTime('d/m/Y H:i'),
                    TextEntry::make('closed_at')->label('Cerrado')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('guests')->label('Comensales')->placeholder('—'),
                    TextEntry::make('notes')->label('Notas')->placeholder('—')->columnSpan(2),
                ]),
                Section::make('Totales')->columnSpan(1)->schema([
                    MoneyEntry::make('subtotal')->label('Subtotal (tras descuentos de línea)'),
                    MoneyEntry::make('discount_amount')->label('Descuento del pedido')
                        ->visible(fn (Order $record) => $record->discount_amount > 0)
                        ->helperText(fn (Order $record) => $record->discount_reason),
                    MoneyEntry::make('delivery_fee')->label('Gastos de envío')
                        ->visible(fn (Order $record) => $record->type === OrderType::Delivery),
                    MoneyEntry::make('total')->label('Total')->size('lg')->weight('bold'),
                ]),
            ]),
            Section::make('Cliente y reparto')->columns(4)->columnSpanFull()
                ->visible(fn (Order $record) => $record->type->isTakeaway() || $record->customer_id)
                ->schema([
                    TextEntry::make('customer_name')->label('Cliente')->placeholder('—'),
                    TextEntry::make('customer_phone')->label('Teléfono')->placeholder('—'),
                    TextEntry::make('delivery_address')->label('Dirección')->placeholder('—')->columnSpan(2)
                        ->state(fn (Order $record) => self::address($record->delivery_address)),
                    TextEntry::make('deliveryZone.name')->label('Zona')->placeholder('—'),
                    TextEntry::make('promised_at')->label('Hora prometida')->placeholder('—')
                        ->state(fn (Order $record) => $record->promisedLabel()),
                    TextEntry::make('courier.name')->label('Repartidor')->placeholder('—'),
                    TextEntry::make('delivery_status')->label('Estado de reparto')->badge()->placeholder('—'),
                    TextEntry::make('planned_payment_method')->label('Pagará con')->placeholder('—'),
                    TextEntry::make('cash_change_for')->label('Cambio para')->placeholder('—')
                        ->formatStateUsing(fn ($state) => Format::money($state)),
                    TextEntry::make('prepaid_method')->label('Ya pagado con')->placeholder('—'),
                    TextEntry::make('dispatched_at')->label('Salida')->dateTime('H:i')->placeholder('—'),
                    TextEntry::make('delivered')->label('Entregado')->placeholder('—')
                        ->state(fn (Order $record) => $record->delivered_at ? $record->delivered_at->format('H:i').' · '.$record->deliveredBy?->name : null),
                    TextEntry::make('courierSettlement.created_at')->label('Liquidado')->dateTime('d/m H:i')->placeholder('—'),
                    TextEntry::make('delivery_failed_reason')->label('No entregado')->placeholder('—')->columnSpan(2),
                    TextEntry::make('cancelled')->label('Cancelado')->placeholder('—')->columnSpan(2)
                        ->state(fn (Order $record) => $record->cancelled_at ? $record->cancelled_at->format('d/m H:i').' · '.$record->cancelledBy?->name.' · '.$record->cancel_reason : null),
                ]),
            Section::make('Mesas unidas')->columnSpanFull()
                ->visible(fn (Order $record) => $record->tableLinks()->where('is_main', false)->exists()
                    || Order::query()->where('merged_into_order_id', $record->id)->exists())
                ->schema([
                    TextEntry::make('table_history')->hiddenLabel()->listWithLineBreaks()
                        ->state(fn (Order $record) => self::tableHistory($record)),
                ]),
            Section::make('Líneas')->columnSpanFull()->schema([
                LinesTree::make('lines'),
            ]),
            Section::make('Tickets')->columnSpanFull()
                ->visible(fn (Order $record) => $record->tickets()->exists())
                ->schema([
                    RepeatableEntry::make('tickets')->label('Tickets')->hiddenLabel()
                        ->table([
                            TableColumn::make('Ticket'),
                            TableColumn::make('Emitido'),
                            TableColumn::make('Total')->alignment('end'),
                            TableColumn::make('Cobro'),
                        ])
                        ->schema([
                            TextEntry::make('number')->label('Ticket')->color('primary')
                                ->formatStateUsing(fn ($record) => $record->fullNumber())
                                ->url(fn ($record) => TicketResource::getUrl('view', ['record' => $record])),
                            TextEntry::make('issued_at')->label('Emitido')->dateTime('d/m/Y H:i'),
                            MoneyEntry::make('total')->label('Total'),
                            TextEntry::make('payment_status')->label('Cobro')->badge()
                                ->formatStateUsing(fn ($record) => $record->collectionLabel())
                                ->color(fn ($record) => $record->collectionColor()),
                        ]),
                ]),
        ]);
    }

    public static function address(?array $address): ?string
    {
        if (! $address) {
            return null;
        }

        return trim(implode(', ', array_filter([
            trim(($address['street'] ?? '').' '.($address['number'] ?? '')),
            $address['floor_door'] ?? null,
            trim(($address['postal_code'] ?? '').' '.($address['city'] ?? '')),
        ]))).(! empty($address['instructions']) ? " ({$address['instructions']})" : '');
    }

    /** Quién unió y separó cada mesa, y cuándo (order_tables y pedidos unidos). */
    private static function tableHistory(Order $record): array
    {
        $rows = [];
        foreach ($record->tableLinks()->where('is_main', false)->with(['diningTable', 'joinedBy', 'leftBy'])->orderBy('joined_at')->get() as $link) {
            $rows[] = "Mesa {$link->diningTable->name}: unida el {$link->joined_at->format('d/m H:i')} por {$link->joinedBy?->name}"
                .($link->left_by ? " · separada el {$link->left_at->format('d/m H:i')} por {$link->leftBy?->name}" : '');
        }
        foreach (Order::query()->where('merged_into_order_id', $record->id)->with('table')->get() as $merged) {
            $rows[] = "Pedido #{$merged->id} de la mesa {$merged->table?->name} unido el {$merged->merged_at?->format('d/m H:i')} por "
                .User::query()->find($merged->merged_by)?->name.' (sus líneas pasaron a este pedido)';
        }

        return $rows;
    }
}
