<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
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
use Illuminate\Database\Eloquent\Builder;

class TicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('Número')
                    ->formatStateUsing(fn (Ticket $record) => $record->fullNumber())
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('year', $direction)->orderBy('number', $direction))
                    ->searchable(query: fn ($query, string $search) => $query->whereNumber($search)),
                TextColumn::make('issued_at')->label('Emitido')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('type')->label('Tipo')->badge()
                    ->color(fn (TicketType $state) => match (true) {
                        $state->isRectifying() => 'danger',
                        $state->isFullInvoice() => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('order_type')->label('Pedido')->badge()->placeholder('—'),
                TextColumn::make('customer_name')->label('Cliente')->placeholder('—')->toggleable()
                    ->state(fn (Ticket $record) => $record->recipient['name'] ?? $record->customer['name'] ?? null)
                    ->description(fn (Ticket $record) => $record->recipient['tax_id'] ?? null),
                MoneyColumn::make('total')->label('Total')->sortable()
                    ->summarize(Sum::make()->label('Suma')->formatStateUsing(fn ($state) => Format::money((int) $state))),
                TextColumn::make('payment_status')->label('Cobro')->badge()
                    ->formatStateUsing(fn (Ticket $record) => $record->collectionLabel())
                    ->color(fn (Ticket $record) => $record->collectionColor()),
                TextColumn::make('status')->label('Estado')->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                DateRangeFilter::make('issued_at', 'Fecha'),
                SelectFilter::make('ticket_series_id')->label('Serie')->relationship('series', 'name'),
                SelectFilter::make('year')->label('Año')
                    ->options(fn () => Ticket::query()->distinct()->orderByDesc('year')->pluck('year', 'year')->all()),
                SelectFilter::make('type')->label('Tipo')->options(TicketType::class),
                SelectFilter::make('order_type')->label('Tipo de pedido')->options(OrderType::class),
                // «Pendiente de cobro» no incluye los anulados (no se cobran).
                SelectFilter::make('payment_status')->label('Cobro')
                    ->options(['pagado' => PaymentStatus::Paid->label(), 'pendiente' => PaymentStatus::Pending->label(), 'anulado' => 'Anulado: no se cobra'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'pagado' => $query->where('payment_status', PaymentStatus::Paid)->where('status', '!=', TicketStatus::Annulled),
                        'pendiente' => $query->where('payment_status', PaymentStatus::Pending)->where('status', '!=', TicketStatus::Annulled),
                        'anulado' => $query->where('status', TicketStatus::Annulled),
                        default => $query,
                    }),
            ])
            ->recordActions([ViewAction::make()])
            ->recordUrl(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record]));
    }
}
