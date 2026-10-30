<?php

namespace App\Services\Cash;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CourierSettlement;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketLine;

/**
 * Informe de caja X (parcial) y Z (cierre) a partir de los tickets emitidos y
 * los pagos registrados en la sesión. Las propinas van aparte: no son ventas
 * ni tienen IVA, pero hacen cuadrar el cierre del datáfono.
 */
class CashReportService
{
    public function build(CashSession $session, ?int $countedCash = null, ?string $notes = null): array
    {
        $documents = Ticket::query()->where('cash_session_id', $session->id)->with(['issuedBy', 'replacedTicket'])->get();
        // Canjes de tickets por factura (art. 15.6): NO son ventas nuevas (el
        // ticket ya contó en su Z). Se listan aparte y no suman.
        $exchanges = $documents->where('type', TicketType::InvoiceExchange);
        $tickets = $documents->reject(fn (Ticket $t) => $t->type === TicketType::InvoiceExchange)->values();
        $payments = Payment::query()->where('cash_session_id', $session->id)->with('user')->get();
        $simplified = $tickets->where('type', TicketType::Simplified);
        $invoices = $tickets->where('type', TicketType::Invoice);
        $rectifying = $tickets->whereIn('type', [TicketType::Rectifying, TicketType::InvoiceRectifying]);

        $invitationLines = TicketLine::query()->whereIn('ticket_id', $tickets->pluck('id'))->whereNull('parent_line_id')->where('is_invitation', true)->get();
        $invitations = (int) $invitationLines->sum('discount_amount');
        $lineDiscounts = (int) $tickets->sum('lines_discount') - $invitations;

        $cashIn = (int) $payments->where('method', PaymentMethod::Cash)->sum('amount');
        // Movimientos que no son venta (fondo de cambio de los repartidores): − sale, + vuelve.
        $movements = CashMovement::query()->where('cash_session_id', $session->id)->with('courier')->orderBy('id')->get();
        $movementsTotal = (int) $movements->sum('amount');
        $expected = $session->opening_float + $cashIn + $movementsTotal;

        // Domicilios con ticket emitido y aún sin cobrar (los lleva el repartidor).
        $pendingDelivery = Ticket::query()
            ->where('order_type', OrderType::Delivery)->whereIn('type', TicketType::orderDocuments())
            ->where('status', TicketStatus::Issued)->where('payment_status', PaymentStatus::Pending)
            ->with(['order.courier', 'payments'])->orderBy('issued_at')->get();
        $settlements = CourierSettlement::query()->where('cash_session_id', $session->id)->with('courier')->orderBy('id')->get();

        $vat = [];
        foreach ($tickets as $ticket) {
            foreach ($ticket->vat_breakdown as $row) {
                $vat[$row['rate']] ??= ['rate' => $row['rate'], 'base' => 0, 'tax' => 0, 'total' => 0];
                $vat[$row['rate']]['base'] += $row['base'];
                $vat[$row['rate']]['tax'] += $row['tax'];
                $vat[$row['rate']]['total'] += $row['total'];
            }
        }
        ksort($vat);

        return [
            'session_id' => $session->id,
            'z_number' => $session->z_number,
            'z_full_number' => $session->z_full_number,
            'opened_at' => $session->opened_at->format('d/m/Y H:i'),
            'generated_at' => now()->format('d/m/Y H:i'),
            'tickets' => ['count' => $simplified->count(), 'total' => (int) $simplified->sum('total')],
            'invoices' => ['count' => $invoices->count(), 'total' => (int) $invoices->sum('total')],
            'rectifications' => ['count' => $rectifying->count(), 'total' => (int) $rectifying->sum('total')],
            'exchanges' => [
                'count' => $exchanges->count(),
                'total' => (int) $exchanges->sum('total'),
                'items' => $exchanges->map(fn (Ticket $t) => [
                    'number' => $t->fullNumber(), 'replaces' => $t->replacedTicket?->fullNumber(), 'total' => $t->total,
                ])->values()->all(),
            ],
            'sales_total' => (int) $tickets->sum('total'),
            'discounts' => ['amount' => $lineDiscounts + (int) $tickets->sum('discount_amount')],
            'invitations' => ['count' => $invitationLines->count(), 'amount' => $invitations],
            // Por forma de pago: cobros, devoluciones (pagos negativos de
            // rectificativos) y neto. amount = neto (compatibilidad).
            'by_method' => collect(PaymentMethod::cases())
                ->map(function (PaymentMethod $m) use ($payments) {
                    $all = $payments->where('method', $m);
                    $charges = $all->where('amount', '>', 0);
                    $refunds = $all->where('amount', '<', 0);

                    return [
                        'method' => $m->value, 'label' => $m->label(),
                        'count' => $charges->count(),
                        'charges' => (int) $charges->sum('amount'),
                        'refunds_count' => $refunds->count(),
                        'refunds' => (int) $refunds->sum('amount'),
                        'amount' => (int) $all->sum('amount'),
                        'tips' => (int) $all->sum('tip'),
                    ];
                })->filter(fn ($r) => $r['count'] > 0 || $r['refunds_count'] > 0)->values()->all(),
            // Lo que debe marcar el cierre del datáfono: neto de tarjeta + propinas de tarjeta.
            'card_terminal_total' => (int) $payments->where('method', PaymentMethod::Card)->sum('amount')
                + (int) $payments->where('method', PaymentMethod::Card)->sum('tip'),
            'tips' => [
                'total' => (int) $payments->sum('tip'),
                'by_method' => $payments->where('tip', '>', 0)->groupBy(fn ($p) => $p->method->value)
                    ->map(fn ($g, $m) => ['method' => $m, 'label' => PaymentMethod::from($m)->label(), 'amount' => (int) $g->sum('tip')])->values()->all(),
                'by_waiter' => $payments->where('tip', '>', 0)->groupBy('user_id')
                    ->map(fn ($g) => ['user_id' => $g->first()->user_id, 'name' => $g->first()->user?->name, 'amount' => (int) $g->sum('tip')])->values()->all(),
            ],
            'by_vat' => array_values($vat),
            'by_waiter' => $tickets->groupBy('issued_by')
                ->map(fn ($g) => ['user_id' => $g->first()->issued_by, 'name' => $g->first()->issuedBy?->name ?? '—', 'count' => $g->count(), 'total' => (int) $g->sum('total')])
                ->sortByDesc('total')->values()->all(),
            'by_order_type' => $tickets->groupBy(fn ($t) => $t->order_type?->value ?? 'barra')
                ->map(fn ($g, $type) => ['type' => $type, 'label' => OrderType::from($type)->label(), 'count' => $g->count(), 'total' => (int) $g->sum('total')])
                ->values()->all(),
            'delivery_pending' => [
                'count' => $pendingDelivery->count(),
                'total' => (int) $pendingDelivery->sum(fn (Ticket $t) => $t->total - $t->paidAmount()),
                'tickets' => $pendingDelivery->map(fn (Ticket $t) => [
                    'ticket_id' => $t->id, 'number' => $t->fullNumber(), 'amount' => $t->total - $t->paidAmount(),
                    'courier' => $t->order?->courier?->name, 'issued_at' => $t->issued_at->format('H:i'),
                ])->values()->all(),
            ],
            'courier_settlements' => $settlements->map(fn (CourierSettlement $s) => [
                'courier' => $s->courier->name, 'cash' => $s->cash_collected, 'card' => $s->card_collected, 'other' => $s->other_collected,
                'float' => $s->float_amount, 'expected' => $s->expected_cash, 'handed' => $s->handed_cash, 'difference' => $s->difference,
            ])->values()->all(),
            'cash_movements' => $movements->map(fn (CashMovement $m) => [
                'type' => $m->type->value, 'label' => $m->type->label(), 'courier' => $m->courier?->name, 'amount' => $m->amount,
                'at' => $m->created_at->format('H:i'),
            ])->values()->all(),
            'cash' => [
                'opening_float' => $session->opening_float,
                'cash_in' => $cashIn,
                'movements' => $movementsTotal,
                'expected' => $expected,
                'counted' => $countedCash,
                'difference' => $countedCash === null ? null : $countedCash - $expected,
            ],
            'notes' => $notes,
        ];
    }
}
