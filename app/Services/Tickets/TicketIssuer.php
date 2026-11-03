<?php

namespace App\Services\Tickets;

use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderTotalsCalculator;
use App\Services\Verifactu\EmissionGuard;
use App\Services\Verifactu\FiscalRecordGenerator;
use App\Support\InvoiceRules;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Emite el documento de un pedido: recalcula totales, numera, congela una copia
 * de las líneas y cierra el pedido. Todo en una transacción: si algo falla no
 * se consume número (numeración correlativa sin huecos, art. 6.1.a).
 *
 *  - Sin destinatario: ticket (factura simplificada, serie T), hasta 3.000 €
 *    (art. 4.2.e RD 1619/2012).
 *  - Con destinatario: factura completa (art. 6), serie F, con la copia de
 *    sus datos fiscales.
 */
class TicketIssuer
{
    public function __construct(
        private TicketNumberService $numbers,
        private OrderTotalsCalculator $totals,
        private OrderService $orders,
    ) {}

    public function issue(Order $order, User $by, ?CashSession $session = null, ?string $idempotencyKey = null, ?Customer $recipient = null): Ticket
    {
        if (! $order->isOpen()) {
            throw new LogicException('Solo se puede emitir ticket de un pedido abierto.');
        }

        EmissionGuard::assertCanIssue();

        return DB::transaction(function () use ($order, $by, $session, $idempotencyKey, $recipient) {
            $settings = Setting::current();
            if ($recipient) {
                InvoiceRules::assertRecipient($recipient);
                $series = $settings->invoiceSeries ?? throw new LogicException('No hay serie de facturas (F) en los ajustes.');
            } else {
                $series = $settings->defaultTicketSeries ?? throw new LogicException('No hay serie de tickets por defecto.');
            }

            $totals = $this->totals->apply($order);
            if (! $recipient) {
                InvoiceRules::assertSimplifiedAllowed($totals->total);
            }
            if ($totals->linesGross === 0 && $order->lines()->whereNull('voided_at')->doesntExist()) {
                throw new LogicException('El pedido no tiene líneas.');
            }

            // Año y número de la misma fecha de emisión que se guarda (art. 6.1.a).
            $issuedAt = now();
            $number = $this->numbers->next($series, $issuedAt);
            $ticket = Ticket::create([
                'ticket_series_id' => $series->id,
                'series_code' => $series->code,
                ...$number->attributes(),
                'type' => $recipient ? TicketType::Invoice : TicketType::Simplified,
                'status' => TicketStatus::Issued,
                'order_id' => $order->id,
                'order_type' => $order->type,
                'table_label' => $order->tableLabel(),   // «Mesa 3 + 4» (snapshot)
                'cash_session_id' => $session?->id,
                'issued_by' => $by->id,
                'issued_at' => $issuedAt,
                'subtotal' => $totals->linesGross,
                'lines_discount' => $totals->linesDiscount,
                'discount_type' => $order->discount_type,
                'discount_value' => $order->discount_value,
                'discount_amount' => $totals->orderDiscount,
                'discount_reason' => $order->discount_reason,
                'delivery_fee' => $totals->deliveryFee,
                'delivery_fee_vat_rate' => $totals->deliveryFee ? $settings->delivery_fee_vat_rate : null,
                'tax_base' => $totals->taxBase(),
                'tax_amount' => $totals->taxAmount(),
                'total' => $totals->total,
                'vat_breakdown' => $totals->vatBreakdown,
                'issuer' => $settings->issuerSnapshot(),
                'customer_id' => $recipient?->id ?? $order->customer_id,
                'recipient' => $recipient?->recipientSnapshot(),
                'customer' => $order->customer_id ? [
                    'name' => $order->customer_name,
                    'phone' => $order->customer_phone,
                ] : null,
                'delivery_address' => $order->delivery_address,
                'idempotency_key' => $idempotencyKey,
                'payment_status' => $totals->total === 0 ? PaymentStatus::Paid : PaymentStatus::Pending,
                'paid_at' => $totals->total === 0 ? now() : null,
            ]);

            $this->copyLines($ticket, $order);
            // Registro de facturación de alta, en la misma transacción (RRSIF 9).
            app(FiscalRecordGenerator::class)->alta($ticket);
            $this->orders->close($order);

            if ($order->customer) {
                $order->customer->increment('orders_count', 1, ['last_order_at' => now()]);
            }

            return $ticket;
        });
    }

    /**
     * Precio unitario sin IVA (art. 6.1.f), con 4 decimales. Líneas simples:
     * importe bruto de la línea (con modificadores) entre unidades. Componentes
     * de menú y mitades: su parte del precio del padre + suplemento +
     * modificadores, por unidad del padre. El padre de un menú/mitad no lleva
     * precio propio (tributa en sus componentes, que pueden tener otro IVA).
     */
    public static function unitPriceNet(OrderLine $line): ?int
    {
        if ($line->parent_line_id === null) {
            if ($line->children()->exists()) {
                return null;
            }

            return InvoiceRules::netUnitE4($line->quantity > 0 ? $line->line_total / $line->quantity : 0, $line->vat_rate);
        }
        $modifiers = $line->modifiers->sum(fn ($m) => $m->price_delta * $m->quantity);

        return InvoiceRules::netUnitE4((int) $line->allocated_amount + (int) $line->unit_price + $modifiers, $line->vat_rate);
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
                'unit_price_net' => self::unitPriceNet($line),
                'vat_rate' => $line->vat_rate,
                'reference_price' => $line->reference_price,
                'allocated_amount' => $line->allocated_amount,
                'half_and_half_surcharge' => $line->half_and_half_surcharge,
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
