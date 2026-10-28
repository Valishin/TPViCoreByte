<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\TicketType;
use App\Filament\Components\DateRangeFilter;
use App\Filament\Components\MoneyColumn;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Support\Format;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('Número')->sortable()
                    ->formatStateUsing(fn (Ticket $record) => $record->fullNumber())
                    ->searchable(query: fn ($query, string $search) => $query->where('number', (int) preg_replace('/\D+/', '', $search) ?: -1)),
                TextColumn::make('issued_at')->label('Emitido')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('type')->label('Tipo')->badge()
                    ->color(fn (TicketType $state) => $state === TicketType::Rectifying ? 'danger' : 'gray'),
                TextColumn::make('order_type')->label('Pedido')->badge()->placeholder('—'),
                TextColumn::make('customer_name')->label('Cliente')->placeholder('—')->toggleable()
                    ->state(fn (Ticket $record) => $record->customer['name'] ?? null),
                MoneyColumn::make('total')->label('Total')->sortable()
                    ->summarize(Sum::make()->label('Suma')->formatStateUsing(fn ($state) => Format::money((int) $state))),
                TextColumn::make('payment_status')->label('Cobro')->badge()
                    ->color(fn (PaymentStatus $state) => $state === PaymentStatus::Paid ? 'success' : 'warning'),
                TextColumn::make('status')->label('Estado')->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                DateRangeFilter::make('issued_at', 'Fecha'),
                SelectFilter::make('ticket_series_id')->label('Serie')->relationship('series', 'name'),
                SelectFilter::make('order_type')->label('Tipo de pedido')->options(OrderType::class),
                SelectFilter::make('payment_status')->label('Cobro')->options(PaymentStatus::class),
            ])
            ->recordActions([ViewAction::make()])
            ->recordUrl(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record]));
    }
}
