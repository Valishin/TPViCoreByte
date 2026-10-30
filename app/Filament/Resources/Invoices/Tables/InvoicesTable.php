<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\TicketType;
use App\Filament\Components\DateRangeFilter;
use App\Filament\Components\MoneyColumn;
use App\Filament\Resources\Tickets\InvoiceActions;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Customer;
use App\Models\InvoiceEmail;
use App\Models\Ticket;
use App\Support\Format;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['replacedTicket', 'rectifiedTicket', 'emails' => fn ($e) => $e->latest('id')]))
            ->columns([
                TextColumn::make('number')->label('Número')->sortable()
                    ->formatStateUsing(fn (Ticket $record) => $record->fullNumber())
                    ->searchable(query: fn ($query, string $search) => $query->where('number', (int) preg_replace('/\D+/', '', $search) ?: -1)),
                TextColumn::make('issued_at')->label('Emitida')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('type')->label('Tipo')->badge()
                    ->color(fn (TicketType $state) => $state === TicketType::InvoiceRectifying ? 'danger' : 'info'),
                TextColumn::make('recipient_name')->label('Destinatario')
                    ->state(fn (Ticket $record) => $record->recipient['name'] ?? null)
                    ->description(fn (Ticket $record) => $record->recipient['tax_id'] ?? null)
                    ->searchable(query: fn ($query, string $search) => $query->where(fn ($q) => $q
                        ->where('recipient->name', 'ilike', "%{$search}%")
                        ->orWhere('recipient->tax_id', 'ilike', strtoupper(preg_replace('/[\s.\-]+/', '', $search)).'%'))),
                TextColumn::make('reference')->label('Referencia')->placeholder('—')
                    ->state(fn (Ticket $record) => match (true) {
                        $record->replacedTicket !== null => 'Sustituye '.$record->replacedTicket->fullNumber(),
                        $record->rectifiedTicket !== null => 'Rectifica '.$record->rectifiedTicket->fullNumber(),
                        default => null,
                    }),
                MoneyColumn::make('total')->label('Total')->sortable()
                    ->summarize(Sum::make()->label('Suma')->formatStateUsing(fn ($state) => Format::money((int) $state))),
                TextColumn::make('email_status')->label('Email')->badge()->placeholder('—')
                    ->state(fn (Ticket $record) => $record->emails->first()?->status)
                    ->color(fn (?string $state) => match ($state) {
                        InvoiceEmail::SENT => 'success', InvoiceEmail::ERROR => 'danger', default => 'warning',
                    })
                    ->tooltip(fn (Ticket $record) => ($e = $record->emails->first()) ? trim($e->to.' '.($e->last_error ?? '')) : null),
                TextColumn::make('status')->label('Estado')->badge(),
            ])
            ->filters([
                DateRangeFilter::make('issued_at', 'Fecha'),
                SelectFilter::make('type')->label('Tipo')->options([
                    TicketType::Invoice->value => TicketType::Invoice->label(),
                    TicketType::InvoiceExchange->value => TicketType::InvoiceExchange->label(),
                    TicketType::InvoiceRectifying->value => TicketType::InvoiceRectifying->label(),
                ]),
                SelectFilter::make('ticket_series_id')->label('Serie')->relationship('series', 'name'),
                SelectFilter::make('customer_id')->label('Cliente')->searchable()
                    ->options(fn () => Customer::query()->whereNotNull('tax_id')->orderBy('legal_name')->limit(500)
                        ->get()->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->legal_name} ({$c->tax_id})"])),
                SelectFilter::make('email')->label('Email')
                    ->options(['none' => 'Sin enviar', InvoiceEmail::PENDING => 'En cola', InvoiceEmail::SENT => 'Enviado', InvoiceEmail::ERROR => 'Con error'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereDoesntHave('emails'),
                        // Estado del último envío de cada factura.
                        default => $query->whereRaw('(select status from invoice_emails e where e.ticket_id = tickets.id order by e.id desc limit 1) = ?', [$data['value']]),
                    }),
            ])
            ->recordActions([ViewAction::make()->url(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record])), InvoiceActions::pdf(), InvoiceActions::email()])
            ->recordUrl(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record]));
    }
}
