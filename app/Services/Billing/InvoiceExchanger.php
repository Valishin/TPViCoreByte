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
use App\Services\Verifactu\EmissionGuard;
use App\Services\Verifactu\FiscalRecordGenerator;
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
 * hoy y la de la operación del ticket (art. 6.1.i), y lo referencia
 * (FacturasSustituidas). El ticket queda "canjeado" y no se vuelve a canjear.
 *
 * Correcciones, según la FAQ 27 de la AEAT para desarrolladores (v1.3):
 *  - Datos del cliente mal en la F3 (caso 1): F3 en negativo con los mismos
 *    importes + F3 nueva con los datos correctos, ambas sustituyendo al ticket.
 *    La F3 errónea queda "rectificada". No se usan R1-R4 para una F3.
 *  - Devolución o rectificación del ticket canjeado (casos 2 y 3): rectificativo
 *    R5 del ticket + F3 de ese R5 para el cliente (ver RectificationService).
 */
class InvoiceExchanger
{
    public function __construct(private TicketNumberService $numbers) {}

    public function exchange(Ticket $ticket, Customer $recipient, User $by, bool $confirmOtherYear = false): Ticket
    {
        InvoiceRules::assertRecipient($recipient);

        EmissionGuard::assertCanIssue();

        return DB::transaction(function () use ($ticket, $recipient, $by, $confirmOtherYear) {
            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $this->assertExchangeable($ticket);
            if (! $confirmOtherYear && $ticket->issued_at->year !== now()->year) {
                throw new ExchangeOtherYearException($ticket->issued_at->year);
            }

            $invoice = $this->issueExchange($ticket, $ticket, $recipient->recipientSnapshot(), $recipient->id, $by);
            $ticket->update(['status' => TicketStatus::Exchanged]);

            return $invoice;
        });
    }

    /**
     * FAQ 27 caso 1: datos del cliente erróneos en una F3. Devuelve la F3 nueva
     * (la negativa queda enlazada al mismo ticket).
     */
    public function correctRecipient(Ticket $exchange, Customer $recipient, User $by): Ticket
    {
        InvoiceRules::assertRecipient($recipient);
        EmissionGuard::assertCanIssue();

        return DB::transaction(function () use ($exchange, $recipient, $by) {
            $exchange = Ticket::query()->whereKey($exchange->id)->lockForUpdate()->firstOrFail();
            if ($exchange->type !== TicketType::InvoiceExchange || $exchange->status !== TicketStatus::Issued || $exchange->total < 0) {
                throw new LogicException('Solo se corrige una factura de canje vigente.');
            }
            $replaced = $exchange->replacedTicket ?? throw new LogicException('La factura de canje no indica a qué ticket sustituye.');

            $this->issueExchange($exchange, $replaced, $exchange->recipient, $exchange->customer_id, $by, negate: true);
            $new = $this->issueExchange($exchange, $replaced, $recipient->recipientSnapshot(), $recipient->id, $by);
            $exchange->update(['status' => TicketStatus::Rectified]);

            return $new;
        });
    }

    /**
     * FAQ 27 casos 2 y 3: F3 del rectificativo R5 de un ticket canjeado, para el
     * mismo cliente que la F3 vigente. Se llama dentro de la transacción del R5.
     */
    public function exchangeRectifying(Ticket $rectifying, Ticket $currentExchange, User $by): Ticket
    {
        return $this->issueExchange($rectifying, $rectifying, $currentExchange->recipient, $currentExchange->customer_id, $by);
    }

    /**
     * Emite una F3 (serie F) con los importes, líneas y desglose de $source
     * (en negativo si $negate) que sustituye a $replaces, y genera su registro.
     */
    private function issueExchange(Ticket $source, Ticket $replaces, array $recipient, ?int $customerId, User $by, bool $negate = false): Ticket
    {
        $settings = Setting::current();
        $series = $settings->invoiceSeries ?? throw new LogicException('No hay serie de facturas (F) en los ajustes.');
        $sign = $negate ? -1 : 1;

        // Año y número de la misma fecha de emisión que se guarda (art. 6.1.a).
        $issuedAt = now();
        $number = $this->numbers->next($series, $issuedAt);
        $invoice = Ticket::create([
            'ticket_series_id' => $series->id,
            'series_code' => $series->code,
            ...$number->attributes(),
            'type' => TicketType::InvoiceExchange,
            'status' => TicketStatus::Issued,
            'order_id' => $source->order_id,
            'order_type' => $source->order_type,
            'table_label' => $source->table_label,
            // Canje: no es una venta nueva (el ticket ya contó en su Z), pero
            // se asocia a la caja abierta para listarlo en ella.
            'cash_session_id' => CheckoutService::openSession()?->id,
            'issued_by' => $by->id,
            'issued_at' => $issuedAt,
            'operation_date' => ($source->operation_date ?? $source->issued_at)->toDateString(),
            'replaces_ticket_id' => $replaces->id,
            'subtotal' => $sign * $source->subtotal,
            'lines_discount' => $sign * $source->lines_discount,
            'discount_type' => $source->discount_type,
            'discount_value' => $source->discount_value,
            'discount_amount' => $sign * $source->discount_amount,
            'discount_reason' => $source->discount_reason,
            'delivery_fee' => $sign * $source->delivery_fee,
            'delivery_fee_vat_rate' => $source->delivery_fee_vat_rate,
            'tax_base' => $sign * $source->tax_base,
            'tax_amount' => $sign * $source->tax_amount,
            'total' => $sign * $source->total,
            'vat_breakdown' => array_map(fn (array $v) => ['rate' => $v['rate'], 'base' => $sign * $v['base'], 'tax' => $sign * $v['tax'], 'total' => $sign * $v['total']], $source->vat_breakdown ?? []),
            'issuer' => $settings->issuerSnapshot(),
            'customer_id' => $customerId,
            'customer' => $source->customer,
            'recipient' => $recipient,
            'delivery_address' => $source->delivery_address,
            'payment_status' => PaymentStatus::Paid, // el cobro está en el ticket
            'paid_at' => $source->paid_at ?? now(),
        ]);
        $this->copyLines($source, $invoice, $sign);
        app(FiscalRecordGenerator::class)->alta($invoice);   // F3 (RRSIF 10.1.h)

        return $invoice;
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

    private function copyLines(Ticket $from, Ticket $to, int $sign = 1): void
    {
        $map = [];
        foreach ($from->lines()->orderByRaw('parent_line_id IS NOT NULL')->orderBy('id')->get() as $line) {
            /** @var TicketLine $line */
            $copy = $line->replicate(['ticket_id', 'parent_line_id']);
            $copy->ticket_id = $to->id;
            $copy->parent_line_id = $line->parent_line_id ? $map[$line->parent_line_id] : null;
            $copy->unit_price_net ??= self::netFromTicketLine($line, $from);
            foreach (['quantity', 'discount_amount', 'order_discount_amount', 'line_total', 'taxable_amount'] as $column) {
                $copy->{$column} = $sign * (int) $copy->{$column};
            }
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
