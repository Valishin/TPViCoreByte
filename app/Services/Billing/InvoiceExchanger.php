<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Exceptions\ExchangeOtherYearException;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Models\User;
use App\Services\Pos\CheckoutService;
use App\Services\Tickets\TicketNumberService;
use App\Support\InvoiceRules;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Canje de un ticket por factura completa: "las facturas que se expidan en
 * sustitución o canje de facturas simplificadas expedidas con anterioridad no
 * tendrán la condición de rectificativas" (art. 15.6 RD 1619/2012).
 *
 * La factura (tipo completa_canje, F3 en VERI*FACTU, serie F) tiene los mismos
 * importes, líneas y desglose de IVA que el ticket, la fecha de expedición de
 * hoy y la de la operación del ticket (art. 6.1.i), y lo referencia. El ticket
 * queda "canjeado": no se puede canjear otra vez ni rectificar (cualquier
 * corrección, sobre la factura).
 */
class InvoiceExchanger
{
    public function __construct(private TicketNumberService $numbers) {}

    public function exchange(Ticket $ticket, Customer $recipient, User $by, bool $confirmOtherYear = false): Ticket
    {
        InvoiceRules::assertRecipient($recipient);

        return DB::transaction(function () use ($ticket, $recipient, $by, $confirmOtherYear) {
            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $this->assertExchangeable($ticket);
            if (! $confirmOtherYear && $ticket->issued_at->year !== now()->year) {
                throw new ExchangeOtherYearException($ticket->issued_at->year);
            }

            $settings = Setting::current();
            $series = $settings->invoiceSeries ?? throw new LogicException('No hay serie de facturas (F) en los ajustes.');

            $invoice = Ticket::create([
                'ticket_series_id' => $series->id,
                'series_code' => $series->code,
                'number' => $this->numbers->next($series),
                'type' => TicketType::InvoiceExchange,
                'status' => TicketStatus::Issued,
                'order_id' => $ticket->order_id,
                'order_type' => $ticket->order_type,
                // Canje: no es una venta nueva (el ticket ya contó en su Z), pero
                // se asocia a la caja abierta para listarlo en ella.
                'cash_session_id' => CheckoutService::openSession()?->id,
                'issued_by' => $by->id,
                'issued_at' => now(),
                'operation_date' => $ticket->issued_at->toDateString(),
                'replaces_ticket_id' => $ticket->id,
                'subtotal' => $ticket->subtotal,
                'lines_discount' => $ticket->lines_discount,
                'discount_type' => $ticket->discount_type,
                'discount_value' => $ticket->discount_value,
                'discount_amount' => $ticket->discount_amount,
                'discount_reason' => $ticket->discount_reason,
                'delivery_fee' => $ticket->delivery_fee,
                'tax_base' => $ticket->tax_base,
                'tax_amount' => $ticket->tax_amount,
                'total' => $ticket->total,
                'vat_breakdown' => $ticket->vat_breakdown,
                'issuer' => $settings->issuerSnapshot(),
                'customer_id' => $recipient->id,
                'customer' => $ticket->customer,
                'recipient' => $recipient->recipientSnapshot(),
                'delivery_address' => $ticket->delivery_address,
                'payment_status' => PaymentStatus::Paid, // el cobro está en el ticket
                'paid_at' => $ticket->paid_at ?? now(),
            ]);
            $this->copyLines($ticket, $invoice);
            $ticket->update(['status' => TicketStatus::Exchanged]);

            return $invoice;
        });
    }

    /** @throws LogicException con el motivo legible */
    public function assertExchangeable(Ticket $ticket): void
    {
        if ($ticket->type !== TicketType::Simplified) {
            throw new LogicException('Solo se canjean tickets (facturas simplificadas).');
        }
        if ($ticket->status === TicketStatus::Exchanged || $ticket->exchangedBy()->exists()) {
            throw new LogicException("Este ticket ya se canjeó por la factura {$ticket->exchangedBy?->fullNumber()}.");
        }
        if ($ticket->status === TicketStatus::Rectified || $ticket->rectifications()->exists()) {
            throw new LogicException('Este ticket tiene rectificativos: no se puede canjear.');
        }
        if ($ticket->payment_status !== PaymentStatus::Paid) {
            throw new LogicException('El ticket está pendiente de cobro: se podrá canjear cuando esté cobrado.');
        }
    }

    private function copyLines(Ticket $from, Ticket $to): void
    {
        $map = [];
        foreach ($from->lines()->orderByRaw('parent_line_id IS NOT NULL')->orderBy('id')->get() as $line) {
            /** @var TicketLine $line */
            $copy = $line->replicate(['ticket_id', 'parent_line_id']);
            $copy->ticket_id = $to->id;
            $copy->parent_line_id = $line->parent_line_id ? $map[$line->parent_line_id] : null;
            $copy->unit_price_net ??= self::netFromTicketLine($line, $from);
            $copy->save();
            $map[$line->id] = $copy->id;
        }
    }

    /** Para tickets anteriores a la factura completa (sin precio sin IVA guardado). */
    private static function netFromTicketLine(TicketLine $line, Ticket $ticket): ?int
    {
        $modifiers = collect($line->modifiers ?? [])->sum(fn ($m) => ($m['price_delta'] ?? 0) * ($m['quantity'] ?? 1));
        if ($line->parent_line_id === null) {
            if ($ticket->lines()->where('parent_line_id', $line->id)->exists()) {
                return null;
            }

            return InvoiceRules::netUnitE4($line->quantity > 0 ? $line->line_total / $line->quantity : 0, $line->vat_rate);
        }

        return InvoiceRules::netUnitE4((int) $line->allocated_amount + (int) $line->unit_price + $modifiers, $line->vat_rate);
    }
}
