<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Components\DateRangeFilter;
use App\Filament\Components\MoneyColumn;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['table.room', 'openedBy']))
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('Nº')->prefix('#')->sortable(),
                TextColumn::make('opened_at')->label('Abierto')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('type')->label('Tipo')->badge(),
                TextColumn::make('where')->label('Mesa / cliente')
                    ->state(fn (Order $record) => $record->table
                        ? "{$record->table->room->name} · Mesa {$record->table->name}"
                        : ($record->customer_name ?? '—')),
                TextColumn::make('openedBy.name')->label('Atendió'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (OrderStatus $state) => match ($state) {
                        OrderStatus::Open => 'warning',
                        OrderStatus::Closed => 'success',
                        OrderStatus::Cancelled => 'gray',
                    }),
                TextColumn::make('delivery_status')->label('Reparto')->badge()->placeholder('—')->toggleable(),
                TextColumn::make('courier.name')->label('Repartidor')->placeholder('—')->toggleable(),
                MoneyColumn::make('total')->label('Total')->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(OrderType::class)->multiple(),
                SelectFilter::make('status')->label('Estado')->options(OrderStatus::class),
                SelectFilter::make('delivery_status')->label('Reparto')->options(DeliveryStatus::class),
                DateRangeFilter::make('opened_at', 'Fecha'),
            ])
            ->recordActions([ViewAction::make()])
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]));
    }
}
