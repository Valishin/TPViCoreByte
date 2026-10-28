<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderType;
use App\Filament\Components\LinesTree;
use App\Filament\Components\MoneyEntry;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Order;
use App\Support\Format;
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
                        ->state(fn (Order $record) => $record->table ? "{$record->table->room->name} · {$record->table->name}" : null),
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
                    TextEntry::make('promised_at')->label('Hora prometida')->dateTime('H:i')->placeholder('—'),
                    TextEntry::make('courier.name')->label('Repartidor')->placeholder('—'),
                    TextEntry::make('delivery_status')->label('Estado de reparto')->badge()->placeholder('—'),
                    TextEntry::make('planned_payment_method')->label('Pagará con')->placeholder('—'),
                    TextEntry::make('cash_change_for')->label('Cambio para')->placeholder('—')
                        ->formatStateUsing(fn ($state) => Format::money($state)),
                ]),
            Section::make('Líneas')->columnSpanFull()->schema([
                LinesTree::make('lines'),
            ]),
            Section::make('Tickets')->columnSpanFull()
                ->visible(fn (Order $record) => $record->tickets()->exists())
                ->schema([
                    TextEntry::make('tickets')->hiddenLabel()->html()
                        ->state(fn (Order $record) => $record->tickets->map(fn ($t) => '<a class="text-primary-600 underline" href="'.TicketResource::getUrl('view', ['record' => $t]).'">'.e($t->fullNumber()).'</a> — '.Format::money($t->total))->implode('<br>')),
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
}
