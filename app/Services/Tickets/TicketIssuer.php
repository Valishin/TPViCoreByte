<?php

namespace App\Services\Tickets;

use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CashSession;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderTotals;
use App\Services\Orders\OrderTotalsCalculator;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Emite el ticket de un pedido: recalcula totales, numera en la serie por
 * defecto, congela una copia de las líneas y cierra el pedido. Todo en una
 * transacción: si algo falla no se consume número.
 */
class TicketIssuer
{
    public function __construct(
        private TicketNumberService $numbers,
        private OrderTotalsCalculator $totals,
        private OrderService $orders,
    ) {}

    public function issue(Order $order, User $by, ?CashSession $session = null): Ticket
    {
        if (! $order->isOpen()) {
            throw new LogicException('Solo se puede emitir ticket de un pedido abierto.');
        }

        return DB::transaction(function () use ($order, $by, $session) {
            $settings = Setting::current();
            $series = $settings->defaultTicketSeries ?? throw new LogicException('No hay serie de tickets por defecto.');

            $totals = $this->totals->apply($order);
            if ($totals->linesGross === 0 && $order->lines()->whereNull('voided_at')->doesntExist()) {
                throw new LogicException('El pedido no tiene líneas.');
            }

            $ticket = Ticket::create([
                'ticket_series_id' => $series->id,
                'series_code' => $series->code,
                'number' => $this->numbers->next($series),
                'type' => TicketType::Simplified,
                'status' => TicketStatus::Issued,
                'order_id' => $order->id,
                'order_type' => $order->type,
                'cash_session_id' => $session?->id,
                'issued_by' => $by->id,
                'issued_at' => now(),
                'subtotal' => $totals->linesGross,
                'lines_discount' => $totals->linesDiscount,
                'discount_type' => $order->discount_type,
                'discount_value' => $order->discount_value,
                'discount_amount' => $totals->orderDiscount,
                'discount_reason' => $order->discount_reason,
                'delivery_fee' => $totals->deliveryFee,
                'tax_base' => $totals->taxBase(),
                'tax_amount' => $totals->taxAmount(),
                'total' => $totals->total,
                'vat_breakdown' => $totals->vatBreakdown,
                'issuer' => $settings->issuerSnapshot(),
                'customer_id' => $order->customer_id,
                'customer' => $order->customer_id ? [
                    'name' => $order->customer_name,
                    'phone' => $order->customer_phone,
                ] : null,
                'delivery_address' => $order->delivery_address,
                'payment_status' => $totals->total === 0 ? PaymentStatus::Paid : PaymentStatus::Pending,
                'paid_at' => $totals->total === 0 ? now() : null,
            ]);

            $this->copyLines($ticket, $order);
            $this->orders->close($order);

            if ($order->customer) {
                $order->customer->increment('orders_count', 1, ['last_order_at' => now()]);
            }

            return $ticket;
        });
    }

    /** Copia congelada de las líneas no anuladas, manteniendo la jerarquía. */
    private function copyLines(Ticket $ticket, Order $order): void
    {
        $lines = $order->lines()->with('modifiers')->whereNull('voided_at')->get();
        $map = []; // order_line_id => ticket_line_id

        foreach ($lines->sortBy(fn (OrderLine $l) => $l->parent_line_id === null ? 0 : 1) as $line) {
            $ticketLine = $ticket->lines()->create([
                'parent_line_id' => $line->parent_line_id ? $map[$line->parent_line_id] : null,
                'order_line_id' => $line->id,
                'kind' => $line->kind,
                'name' => $line->name,
                'variant_name' => $line->variant_name,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'vat_rate' => $line->vat_rate,
                'reference_price' => $line->reference_price,
                'allocated_amount' => $line->allocated_amount,
                'modifiers' => $line->modifiers->map->only(['name', 'price_delta', 'quantity'])->values()->all() ?: null,
                'discount_type' => $line->discount_type,
                'discount_value' => $line->discount_value,
                'discount_amount' => $line->discount_amount,
                'is_invitation' => $line->is_invitation,
                'discount_reason' => $line->discount_reason,
                'order_discount_amount' => $line->order_discount_amount,
                'line_total' => $line->line_total,
                'taxable_amount' => $line->taxable_amount,
                'sort_order' => $line->sort_order,
            ]);
            $map[$line->id] = $ticketLine->id;
        }
    }
}
