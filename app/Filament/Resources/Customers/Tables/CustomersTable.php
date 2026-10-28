<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_order_at', 'desc')
            ->searchPlaceholder('Buscar por teléfono o nombre')
            ->columns([
                TextColumn::make('name')->label('Nombre')->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('name', 'ilike', "%{$search}%")),
                TextColumn::make('phone')->label('Teléfono')->placeholder('—')
                    ->searchable(query: function (Builder $query, string $search) {
                        // Da igual cómo se escriba: "612 34", "+34 612…"
                        $digits = preg_replace('/\D+/', '', $search);
                        $digits === '' ? $query->whereRaw('false') : $query->where('phone', 'like', "%{$digits}%");
                    }),
                TextColumn::make('addresses_count')->label('Direcciones')->counts('addresses')->alignEnd(),
                TextColumn::make('orders_count')->label('Pedidos')->sortable()->alignEnd(),
                TextColumn::make('last_order_at')->label('Último pedido')->dateTime('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('anonymized_at')->label('')->badge()->color('gray')
                    ->formatStateUsing(fn () => 'Anonimizado'),
            ])
            ->filters([
                TernaryFilter::make('anonymized')->label('Anonimizados')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('anonymized_at'),
                        false: fn (Builder $q) => $q->whereNull('anonymized_at'),
                    )->default(false),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (Customer $record) => ! $record->isAnonymized()),
                ActionGroup::make([
                    CustomerResource::exportAction(),
                    CustomerResource::anonymizeAction(),
                ]),
            ]);
    }
}
