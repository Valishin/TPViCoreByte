<?php

namespace App\Http\Controllers\Pos;

use App\Enums\AuthorizationAction;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Billing\InvoiceExchanger;
use App\Services\Pos\AuthorizationService;
use App\Services\Pos\CheckoutService;
use App\Services\Tickets\RectificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tickets desde la caja: detalle, reimprimir (COPIA) y anular (rectificativo por diferencias). */
class TicketController extends Controller
{
    public function show(Ticket $ticket, RectificationService $rectifications): JsonResponse
    {
        $ticket->load(['lines', 'payments', 'order.table', 'issuedBy', 'rectifiedTicket', 'rectifications']);

        return response()->json(['data' => self::summary($ticket) + [
            'lines' => $ticket->lines->map(fn ($l) => [
                'id' => $l->id, 'parent_line_id' => $l->parent_line_id, 'kind' => $l->kind->value, 'name' => $l->name,
                'variant_name' => $l->variant_name, 'quantity' => $l->quantity, 'line_total' => $l->line_total,
                'modifiers' => $l->modifiers ?? [], 'is_invitation' => $l->is_invitation, 'discount_amount' => $l->discount_amount,
            ])->values(),
            'vat_breakdown' => $ticket->vat_breakdown,
            'payments' => $ticket->payments->map(fn ($p) => [
                'method' => $p->method->value, 'amount' => $p->amount, 'tendered' => $p->tendered, 'change' => $p->change, 'tip' => $p->tip,
            ])->values(),
            'remaining' => $ticket->type->value === 'simplificada' ? $rectifications->remaining($ticket) : [],
            'rectifications' => $ticket->rectifications->map(fn ($r) => ['id' => $r->id, 'number' => $r->fullNumber(), 'total' => $r->total])->values(),
            'reprints' => CheckoutService::reprintCount($ticket),
        ]]);
    }

    public function reprint(Request $request, Ticket $ticket, CheckoutService $checkout, AuthorizationService $authorization): JsonResponse
    {
        $data = $request->validate(['authorization_token' => ['nullable', 'string']]);
        // La primera copia es libre; a partir de la segunda, con autorización.
        if (CheckoutService::reprintCount($ticket) >= 1) {
            $authorization->require(AuthorizationAction::Reprint, $request->user(), $data['authorization_token'] ?? null, $ticket);
        }
        $checkout->reprint($ticket, $request->user());

        return response()->json(['status' => 'queued', 'reprints' => CheckoutService::reprintCount($ticket)]);
    }

    public function rectify(Request $request, Ticket $ticket, RectificationService $rectifications, AuthorizationService $authorization): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
            'lines' => ['nullable', 'array'], // ticket_line_id => unidades; vacío = anulación total
            'lines.*' => ['integer', 'min:0'],
            'refund_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'authorization_token' => ['nullable', 'string'],
        ]);
        $authorization->require(AuthorizationAction::VoidTicket, $request->user(), $data['authorization_token'] ?? null, $ticket, $data['reason']);

        $rectifying = $rectifications->byDifferences(
            $ticket, $request->user(), $data['reason'],
            empty($data['lines']) ? null : array_map('intval', $data['lines']),
            isset($data['refund_method']) ? PaymentMethod::from($data['refund_method']) : null,
        );

        return response()->json(['data' => self::summary($rectifying->load(['order.table', 'issuedBy', 'payments', 'rectifiedTicket']))], 201);
    }

    /** Canjear el ticket por una factura completa con los datos de un cliente (art. 15.6). */
    public function exchange(Request $request, Ticket $ticket, InvoiceExchanger $exchanger): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'confirm_other_year' => ['boolean'],
        ]);
        $invoice = $exchanger->exchange($ticket, Customer::query()->findOrFail($data['customer_id']), $request->user(), $data['confirm_other_year'] ?? false);

        return response()->json(['data' => self::summary($invoice->load(['order.table', 'issuedBy', 'payments', 'rectifiedTicket', 'replacedTicket']))], 201);
    }

    public static function summary(Ticket $ticket): array
    {
        $ticket->loadMissing(['exchangedBy', 'replacedTicket']);

        return [
            'id' => $ticket->id,
            'number' => $ticket->fullNumber(),
            'type' => $ticket->type->value,
            'status' => $ticket->status->value,
            'issued_at' => $ticket->issued_at->toIso8601String(),
            'order_type' => $ticket->order_type?->value,
            'table' => $ticket->order?->table?->name,
            'issued_by' => $ticket->issuedBy?->name,
            'total' => $ticket->total,
            'payment_methods' => $ticket->payments->pluck('method')->map->value->unique()->values(),
            'rectifies' => $ticket->rectifiedTicket?->fullNumber(),
            'type_label' => $ticket->type->label(),
            'recipient' => $ticket->recipient ? ['name' => $ticket->recipient['name'] ?? null, 'tax_id' => $ticket->recipient['tax_id'] ?? null] : null,
            'replaces' => $ticket->replacedTicket?->fullNumber(),
            'exchanged_by' => $ticket->exchangedBy?->fullNumber(),
            // Se puede canjear: ticket cobrado, sin rectificativos ni canje.
            'can_exchange' => $ticket->type->value === 'simplificada' && $ticket->status->value === 'emitido'
                && $ticket->payment_status->value === 'pagado',
            'other_year' => $ticket->issued_at->year !== now()->year,
        ];
    }
}
