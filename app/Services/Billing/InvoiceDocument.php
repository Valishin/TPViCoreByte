<?php

namespace App\Services\Billing;

use App\Enums\RectificationMethod;
use App\Enums\TicketType;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Support\InvoiceRules;

/**
 * Contenido de una factura completa (art. 6.1 RD 1619/2012), igual para la
 * impresora de tickets y para el PDF:
 *   a) serie y número · b) fecha de expedición · i) fecha de la operación si es
 *   distinta · c, d, e) emisor y destinatario (nombre o razón social, NIF y
 *   domicilio) · f) descripción con precio unitario sin IVA y descuentos ·
 *   g) tipos · h) cuotas por separado · base por tipo (6.2.c) · total.
 * Rectificativas: identifican la factura rectificada (art. 15.4). Canje: la
 * simplificada sustituida (art. 15.6).
 */
class InvoiceDocument
{
    /** @return array<string, mixed> */
    public static function make(Ticket $ticket): array
    {
        $ticket->loadMissing(['lines', 'payments', 'rectifiedTicket', 'replacedTicket']);
        $rectifying = $ticket->type === TicketType::InvoiceRectifying;
        $operation = $ticket->operation_date;
        $showOperation = $operation && $operation->toDateString() !== $ticket->issued_at->toDateString();

        return [
            'title' => $rectifying ? 'FACTURA RECTIFICATIVA' : 'FACTURA',
            'number' => $ticket->fullNumber(),
            'issued_at' => $ticket->issued_at->format('d/m/Y H:i'),
            'issued_date' => $ticket->issued_at->format('d/m/Y'),
            'operation_date' => $showOperation ? $operation->format('d/m/Y') : null,
            'replaces' => $ticket->replacedTicket
                ? 'Sustituye a la factura simplificada '.$ticket->replacedTicket->fullNumber().' de '.$ticket->replacedTicket->issued_at->format('d/m/Y')
                : null,
            'rectifies' => $rectifying ? [
                'number' => $ticket->rectifiedTicket?->fullNumber(),
                'date' => $ticket->rectifiedTicket?->issued_at->format('d/m/Y'),
                'method' => $ticket->rectification_method === RectificationMethod::Substitution
                    ? 'por sustitución (importes corregidos)'
                    : 'por diferencias (importes que se rectifican)',
                'reason' => $ticket->rectification_reason,
                'reason_label' => $ticket->rectificationReason()?->label(),
            ] : null,
            'issuer' => self::party($ticket->issuer ?? [], issuer: true),
            'recipient' => self::party($ticket->recipient ?? []),
            'rows' => self::rows($ticket),
            'delivery_fee' => $ticket->delivery_fee,
            'subtotal' => $ticket->subtotal,
            'lines_discount' => $ticket->lines_discount,
            'discount_amount' => $ticket->discount_amount,
            'discount_reason' => $ticket->discount_reason,
            'vat_breakdown' => $ticket->vat_breakdown,
            'tax_base' => $ticket->tax_base,
            'tax_amount' => $ticket->tax_amount,
            'total' => $ticket->total,
            'payments' => $ticket->payments,
            'footer' => (string) Setting::query()->value('ticket_footer'),
            'verifactu_type' => $ticket->verifactuType(),
        ];
    }

    /** Nombre, NIF y domicilio en líneas (c, d, e). */
    private static function party(array $p, bool $issuer = false): array
    {
        $country = $p['country'] ?? 'ES';

        return [
            'name' => $issuer ? ($p['legal_name'] ?? '') : ($p['name'] ?? ''),
            'trade_name' => $issuer ? ($p['trade_name'] ?? null) : null,
            'tax_id' => $p['tax_id'] ?? '',
            'address' => $p['address'] ?? null,
            'place' => trim(($p['postal_code'] ?? '').' '.($p['city'] ?? '')).(! empty($p['province']) ? " ({$p['province']})" : ''),
            'country' => $country !== 'ES' ? $country : null,
            'phone' => $issuer ? ($p['phone'] ?? null) : null,
        ];
    }

    /**
     * Filas: una por línea simple; en menús y mitad y mitad, la línea como
     * descripción y una fila por componente (cada uno con su IVA).
     *
     * @return list<array{description: string, quantity: int|null, unit_net: int|null, vat_rate: int|null, amount: int|null, child: bool, discount: int, invitation: bool}>
     */
    private static function rows(Ticket $ticket): array
    {
        $lines = $ticket->lines;
        $rows = [];
        foreach ($lines->whereNull('parent_line_id')->sortBy('sort_order') as $line) {
            $children = $lines->where('parent_line_id', $line->id);
            $rows[] = [
                'description' => self::describe($line),
                'quantity' => $line->quantity,
                'unit_net' => $children->isEmpty() ? ($line->unit_price_net ?? self::fallbackNet($line)) : null,
                'vat_rate' => $children->isEmpty() ? $line->vat_rate : null,
                'amount' => $line->line_total,
                'child' => false,
                'discount' => (int) $line->discount_amount,
                'invitation' => (bool) $line->is_invitation,
            ];
            foreach ($children as $child) {
                $rows[] = [
                    'description' => ($child->kind->value === 'mitad' ? '½ ' : '').self::describe($child),
                    'quantity' => $line->quantity,
                    'unit_net' => $child->unit_price_net ?? self::fallbackNet($child),
                    'vat_rate' => $child->vat_rate,
                    'amount' => null,
                    'child' => true,
                    'discount' => 0,
                    'invitation' => false,
                ];
            }
        }

        return $rows;
    }

    private static function describe(TicketLine $line): string
    {
        $mods = collect($line->modifiers ?? [])->map(fn ($m) => ($m['quantity'] ?? 1) > 1 ? "{$m['quantity']}x {$m['name']}" : $m['name']);

        return $line->name.($line->variant_name ? " ({$line->variant_name})" : '').($mods->isNotEmpty() ? ' + '.$mods->implode(', ') : '');
    }

    private static function fallbackNet(TicketLine $line): int
    {
        return InvoiceRules::netUnitE4($line->quantity > 0 ? $line->line_total / max(1, $line->quantity) : 0, $line->vat_rate);
    }
}
