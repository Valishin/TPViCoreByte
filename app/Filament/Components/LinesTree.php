<?php

namespace App\Filament\Components;

use App\Enums\OrderLineKind;
use App\Models\OrderLine;
use App\Models\TicketLine;
use App\Support\Format;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;

/**
 * Líneas de pedido o de ticket en árbol: cada línea seguida de sus
 * componentes (menú) o mitades, con modificadores, descuentos e IVA.
 */
class LinesTree
{
    public static function make(string $relationship = 'lines'): RepeatableEntry
    {
        return RepeatableEntry::make($relationship)
            ->label('Líneas')->hiddenLabel()
            ->table([
                TableColumn::make('Producto'),
                TableColumn::make('Cant.')->alignment('end'),
                TableColumn::make('Precio')->alignment('end'),
                TableColumn::make('IVA')->alignment('end'),
                TableColumn::make('Descuento')->alignment('end'),
                TableColumn::make('Importe')->alignment('end'),
            ])
            ->schema([
                TextEntry::make('name')->label('Producto')
                    ->formatStateUsing(fn (OrderLine|TicketLine $record) => self::name($record))
                    ->helperText(fn (OrderLine|TicketLine $record) => self::details($record))
                    ->weight(fn (OrderLine|TicketLine $record) => $record->parent_line_id ? null : 'bold')
                    ->color(fn (OrderLine|TicketLine $record) => ($record instanceof OrderLine && $record->isVoided()) ? 'danger' : null),
                TextEntry::make('quantity')->label('Cantidad')->formatStateUsing(fn ($state, $record) => $record->parent_line_id ? "×{$state}" : $state),
                TextEntry::make('unit_price')->label('Precio')->formatStateUsing(fn (OrderLine|TicketLine $record) => self::price($record)),
                TextEntry::make('vat_rate')->label('IVA')->formatStateUsing(fn ($state, $record) => self::isComposite($record) ? '' : Format::percent($state)),
                TextEntry::make('discount_amount')->label('Descuento')->formatStateUsing(fn (OrderLine|TicketLine $record) => self::discount($record)),
                TextEntry::make('line_total')->label('Importe')->formatStateUsing(fn (OrderLine|TicketLine $record) => $record->parent_line_id ? '' : Format::money($record->line_total)),
            ]);
    }

    private static function name(OrderLine|TicketLine $line): string
    {
        $prefix = $line->parent_line_id ? '└ ' : '';
        $variant = $line->variant_name && ! str_contains($line->name, 'Mitad y mitad') ? " ({$line->variant_name})" : '';
        $kind = $line->kind === OrderLineKind::Half ? '½ ' : '';
        $voided = $line instanceof OrderLine && $line->isVoided() ? ' — ANULADA' : '';

        return $prefix.$kind.$line->name.$variant.$voided;
    }

    private static function details(OrderLine|TicketLine $line): ?string
    {
        $modifiers = $line instanceof OrderLine
            ? $line->modifiers->map(fn ($m) => ['name' => $m->name, 'price_delta' => $m->price_delta, 'quantity' => $m->quantity])
            : collect($line->modifiers ?? []);

        $parts = $modifiers->map(fn ($m) => ($m['quantity'] > 1 ? "{$m['quantity']}× " : '').$m['name']
            .($m['price_delta'] ? ' +'.Format::money($m['price_delta']) : ''))->all();

        if ($line->notes ?? null) {
            $parts[] = '«'.$line->notes.'»';
        }
        if ($line->parent_line_id && $line->allocated_amount !== null) {
            $parts[] = 'parte del precio: '.Format::money($line->allocated_amount);
        }
        if ($line instanceof OrderLine && $line->isVoided()) {
            $parts[] = 'Motivo: '.$line->void_reason;
        }

        return $parts ? implode(' · ', $parts) : null;
    }

    private static function price(OrderLine|TicketLine $line): string
    {
        if ($line->parent_line_id) {
            return $line->unit_price ? '+'.Format::money($line->unit_price) : '';
        }

        return Format::money($line->unit_price);
    }

    private static function discount(OrderLine|TicketLine $line): string
    {
        if ($line->is_invitation) {
            return 'Invitación';
        }
        $total = $line->discount_amount + $line->order_discount_amount;

        return $total ? '−'.Format::money($total) : '';
    }

    private static function isComposite(OrderLine|TicketLine $line): bool
    {
        return $line->parent_line_id === null && $line->taxable_amount === 0 && $line->line_total > 0 && ! $line->is_invitation;
    }
}
