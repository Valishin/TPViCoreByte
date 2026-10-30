<?php

namespace App\Services\Tickets;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PrintJobKind;
use App\Enums\RectificationMethod;
use App\Enums\RectificationReason;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Exceptions\CashSessionRequiredException;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Models\User;
use App\Services\Pos\CheckoutService;
use App\Services\Pos\PrintJobDispatcher;
use App\Services\Pos\ReceiptFormatter;
use App\Services\Verifactu\EmissionGuard;
use App\Services\Verifactu\FiscalRecordGenerator;
use App\Support\InvoiceRules;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Tickets rectificativos. El original NO se modifica (solo pasa a
 * "rectificado"); el rectificativo va en la serie de rectificativos con su
 * propia numeración correlativa y lo referencia.
 *
 * VeriFactu: las rectificativas de facturas simplificadas son del tipo R5
 * (TipoFactura = R5). En la fase de VeriFactu, TipoRectificativa será
 * "I" (por diferencias) o "S" (por sustitución, con ImporteRectificacion =
 * base/cuota del original, que aquí se guarda en `rectified_amounts`).
 *
 * - Por diferencias (caja): importes en negativo de lo que se anula, total o
 *   parcialmente. Se registra la devolución (pago negativo en la caja
 *   abierta: resta del efectivo esperado si es en efectivo).
 * - Por sustitución (solo Filament): el rectificativo contiene el ticket ya
 *   corregido; la devolución es la diferencia con el original.
 */
class RectificationService
{
    public function __construct(
        private TicketNumberService $numbers,
        private PrintJobDispatcher $printing,
        private ReceiptFormatter $formatter,
    ) {}

    /**
     * @param  array<int, int>|null  $quantities  ticket_line_id (raíz) => unidades a anular; null = todo
     * @param  bool  $refund  false: sin devolución (p. ej. domicilio no entregado: no se llegó a cobrar)
     */
    public function byDifferences(Ticket $original, User $by, string $reason, ?array $quantities = null, ?PaymentMethod $refundMethod = null, bool $print = true, bool $refund = true, ?RectificationReason $reasonCode = null): Ticket
    {
        return $this->rectify($original, $by, $reason, RectificationMethod::Differences, $quantities, $refundMethod, $print, $refund, $reasonCode);
    }

    /**
     * @param  array<int, int>  $removed  ticket_line_id (raíz) => unidades que se quitan del ticket corregido
     */
    public function bySubstitution(Ticket $original, User $by, string $reason, array $removed, ?PaymentMethod $refundMethod = null, bool $print = false, ?RectificationReason $reasonCode = null, ?Customer $newRecipient = null): Ticket
    {
        return $this->rectify($original, $by, $reason, RectificationMethod::Substitution, $removed, $refundMethod, $print, true, $reasonCode, $newRecipient);
    }

    /**
     * Factura completa con datos del destinatario erróneos: rectificativa por
     * sustitución con los mismos importes y los datos correctos (art. 15.1: la
     * factura no cumple el art. 6).
     */
    public function correctRecipient(Ticket $original, Customer $newRecipient, User $by, string $reason, bool $print = false): Ticket
    {
        if (! $original->type->isFullInvoice()) {
            throw new LogicException('Solo las facturas completas tienen destinatario.');
        }
        InvoiceRules::assertRecipient($newRecipient);

        return $this->bySubstitution($original, $by, $reason, [], print: $print, reasonCode: RectificationReason::RecipientData, newRecipient: $newRecipient);
    }

    /** Unidades de cada línea raíz que aún se pueden rectificar. */
    public function remaining(Ticket $original): array
    {
        $rectified = TicketLine::query()
            ->whereIn('ticket_id', $original->rectifications()->where('rectification_method', RectificationMethod::Differences)->pluck('id'))
            ->whereNull('parent_line_id')
            ->get()
            ->groupBy('order_line_id')
            ->map(fn ($lines) => (int) -$lines->sum('quantity'));

        return $original->lines()->whereNull('parent_line_id')->get()
            ->mapWithKeys(fn (TicketLine $l) => [$l->id => $l->quantity - ($rectified[$l->order_line_id] ?? 0)])
            ->all();
    }

    private function rectify(Ticket $original, User $by, string $reason, RectificationMethod $method, ?array $quantities, ?PaymentMethod $refundMethod, bool $print, bool $withRefund = true, ?RectificationReason $reasonCode = null, ?Customer $newRecipient = null): Ticket
    {
        // Se rectifican tickets (→ rectificativo R5, serie R) y facturas completas,
        // directas o de canje (→ rectificativa R1-R4, serie FR): art. 6.1.a 2.º y 15.
        if (! in_array($original->type, [TicketType::Simplified, TicketType::Invoice, TicketType::InvoiceExchange], true)) {
            throw new LogicException('Solo se pueden rectificar tickets y facturas (no otro rectificativo).');
        }
        $fullInvoice = $original->type->isFullInvoice();
        if ($original->status === TicketStatus::Exchanged) {
            // Canjeado por una factura completa: cualquier corrección, sobre la factura.
            throw new LogicException('Este ticket se canjeó por la factura '.$original->exchangedBy?->fullNumber().': rectifica la factura.');
        }
        if (blank($reason)) {
            throw new InvalidArgumentException('Indica el motivo.');
        }
        if ($method === RectificationMethod::Substitution && $original->rectifications()->exists()) {
            throw new LogicException('Este ticket ya tiene rectificativos: por sustitución solo se puede rectificar una vez.');
        }
        $session = CheckoutService::openSession() ?? throw new CashSessionRequiredException;

        EmissionGuard::assertCanIssue();

        return DB::transaction(function () use ($original, $by, $reason, $method, $quantities, $refundMethod, $print, $session, $withRefund, $reasonCode, $newRecipient, $fullInvoice) {
            $original = Ticket::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            $roots = $original->lines()->whereNull('parent_line_id')->get();
            $children = $original->lines()->whereNotNull('parent_line_id')->get()->groupBy('parent_line_id');
            $remaining = $this->remaining($original);

            // Unidades afectadas por línea raíz.
            $affected = [];
            foreach ($roots as $root) {
                $units = $quantities === null ? $remaining[$root->id] : (int) ($quantities[$root->id] ?? 0);
                if ($units < 0 || $units > $remaining[$root->id]) {
                    throw new InvalidArgumentException("No se pueden rectificar {$units} de «{$root->name}» (quedan {$remaining[$root->id]}).");
                }
                $affected[$root->id] = $units;
            }
            // Sin líneas afectadas solo vale la corrección del destinatario (sustitución).
            if (array_sum($affected) === 0 && ! ($newRecipient && $method === RectificationMethod::Substitution)) {
                throw new InvalidArgumentException('Elige qué anular.');
            }

            $settings = Setting::current();
            $series = $fullInvoice
                ? ($settings->invoiceRectifyingSeries ?? throw new LogicException('No hay serie de rectificativas de facturas (FR).'))
                : ($settings->rectifyingTicketSeries ?? throw new LogicException('No hay serie de rectificativos.'));

            // Líneas del rectificativo: por diferencias, lo anulado en negativo;
            // por sustitución, lo que queda (el ticket corregido).
            $plan = [];
            foreach ($roots as $root) {
                $units = $method === RectificationMethod::Differences ? $affected[$root->id] : $root->quantity - $affected[$root->id];
                if ($units > 0) {
                    $plan[] = [$root, $units, $method === RectificationMethod::Differences ? -1 : 1];
                }
            }

            // Lo ya rectificado (por diferencias) de cada línea, para que varias
            // anulaciones parciales sumen exactamente el original.
            $done = TicketLine::query()
                ->whereIn('ticket_id', $original->rectifications()->where('rectification_method', RectificationMethod::Differences)->pluck('id'))
                ->get()->groupBy('order_line_id');
            $left = fn (TicketLine $line, string $field) => $line->{$field} + (int) ($done->get($line->order_line_id)?->sum($field) ?? 0);

            $byRate = [];
            $subtotal = $linesDiscount = $orderDiscount = 0;
            $lineRows = [];
            foreach ($plan as [$root, $units, $sign]) {
                // Por diferencias: parte de lo que queda; por sustitución: parte del original.
                $pool = $method === RectificationMethod::Differences ? $remaining[$root->id] : $root->quantity;
                $share = fn (TicketLine $line, string $field) => $sign * $this->share(
                    $method === RectificationMethod::Differences ? $left($line, $field) : $line->{$field}, $units, $pool);

                $lineRows[] = [$root, $units * $sign, $share, $children->get($root->id, collect())];
                $subtotal += $share($root, 'line_total');
                $linesDiscount += $share($root, 'discount_amount');
                $orderDiscount += $share($root, 'order_discount_amount');
                foreach (collect([$root])->merge($children->get($root->id, collect())) as $line) {
                    if ($line->taxable_amount) {
                        $byRate[$line->vat_rate] = ($byRate[$line->vat_rate] ?? 0) + $share($line, 'taxable_amount');
                    }
                }
            }

            // Envío: se devuelve solo en la anulación total; en sustitución se mantiene.
            $fullCancel = $method === RectificationMethod::Differences && $original->rectifications()->doesntExist()
                && collect($affected)->every(fn ($units, $id) => $units === $remaining[$id]);
            $deliveryFee = match (true) {
                $fullCancel => -$original->delivery_fee,
                $method === RectificationMethod::Substitution => $original->delivery_fee,
                default => 0,
            };
            // Con el tipo que se aplicó en el original (si cambió el ajuste, no se recalcula).
            $deliveryRate = $deliveryFee ? ($original->delivery_fee_vat_rate ?? $settings->delivery_fee_vat_rate) : null;
            if ($deliveryFee) {
                $rate = $deliveryRate;
                $byRate[$rate] = ($byRate[$rate] ?? 0) + $deliveryFee;
            }
            ksort($byRate);
            $breakdown = [];
            foreach ($byRate as $rate => $amount) {
                if ($amount !== 0) {
                    $base = Money::vatBase($amount, $rate);
                    $breakdown[] = ['rate' => $rate, 'base' => $base, 'tax' => $amount - $base, 'total' => $amount];
                }
            }
            // El total sale del desglose de IVA (siempre cuadran) y la "suma de líneas" se ajusta a él.
            $total = array_sum(array_column($breakdown, 'total'));
            $subtotal = $total - $deliveryFee + $linesDiscount + $orderDiscount;

            // Año y número de la misma fecha de emisión que se guarda (art. 6.1.a).
            $issuedAt = now();
            $number = $this->numbers->next($series, $issuedAt);
            $rectifying = Ticket::create([
                'ticket_series_id' => $series->id,
                'series_code' => $series->code,
                ...$number->attributes(),
                'type' => $original->type->rectifyingType(),
                'status' => TicketStatus::Issued,
                'order_id' => $original->order_id,
                'order_type' => $original->order_type,
                'cash_session_id' => $session->id,
                'issued_by' => $by->id,
                'issued_at' => $issuedAt,
                'rectified_ticket_id' => $original->id,
                'rectification_method' => $method,
                'rectification_reason' => $reason,
                // Motivo codificado (tipo R1-R4 de VERI*FACTU) solo en facturas completas; los tickets son R5.
                'rectification_reason_code' => $fullInvoice
                    ? ($reasonCode ?? ($newRecipient ? RectificationReason::RecipientData : RectificationReason::ErrorOrDiscount))->value
                    : null,
                'rectified_amounts' => $method === RectificationMethod::Substitution
                    ? ['base' => $original->tax_base, 'tax' => $original->tax_amount, 'total' => $original->total] : null,
                'subtotal' => $subtotal,
                'lines_discount' => $linesDiscount,
                'discount_type' => $original->discount_type,
                'discount_value' => $original->discount_value,
                'discount_amount' => $orderDiscount,
                'discount_reason' => $original->discount_reason,
                'delivery_fee' => $deliveryFee,
                'delivery_fee_vat_rate' => $deliveryRate,
                'tax_base' => array_sum(array_column($breakdown, 'base')),
                'tax_amount' => array_sum(array_column($breakdown, 'tax')),
                'total' => $total,
                'vat_breakdown' => $breakdown,
                'issuer' => $settings->issuerSnapshot(),
                'customer_id' => $newRecipient?->id ?? $original->customer_id,
                'customer' => $original->customer,
                'recipient' => $newRecipient?->recipientSnapshot() ?? $original->recipient,
                'operation_date' => $original->operation_date ?? $original->issued_at->toDateString(),
                'delivery_address' => $original->delivery_address,
                'payment_status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ]);

            foreach ($lineRows as [$root, $quantity, $share, $kids]) {
                $parent = $this->copyLine($rectifying, $root, $quantity, $share, null);
                foreach ($kids as $child) {
                    // Las hijas van por unidad del padre: su cantidad no cambia.
                    $this->copyLine($rectifying, $child, $child->quantity, $share, $parent->id);
                }
            }

            // Devolución: lo que el cliente recupera (pago negativo en la caja abierta).
            $refund = $method === RectificationMethod::Differences ? $total : $total - $original->total;
            if ($refund < 0 && $withRefund) {
                $rectifying->payments()->create([
                    // Factura de canje: el cobro está en el ticket que sustituyó.
                    'method' => $refundMethod
                        ?? ($original->replacedTicket ?? $original)->payments()->orderByDesc('amount')->first()?->method
                        ?? PaymentMethod::Cash,
                    'amount' => $refund,
                    'user_id' => $by->id,
                    'cash_session_id' => $session->id,
                    'paid_at' => now(),
                ]);
            }

            app(FiscalRecordGenerator::class)->alta($rectifying);   // R1-R5 (RRSIF 10.1.g)
            $original->update(['status' => TicketStatus::Rectified]);

            if ($print && ($printer = PrintJobDispatcher::ticketPrinter())) {
                $this->printing->dispatch(null, $printer, PrintJobKind::Receipt, $this->formatter->ticket($rectifying->refresh(), $printer->columns), by: $by, forTicket: $rectifying, session: $session);
            }

            return $rectifying;
        });
    }

    private function copyLine(Ticket $ticket, TicketLine $line, int $quantity, \Closure $share, ?int $parentId): TicketLine
    {
        return $ticket->lines()->create([
            'parent_line_id' => $parentId,
            'order_line_id' => $line->order_line_id,
            'kind' => $line->kind,
            'name' => $line->name,
            'variant_name' => $line->variant_name,
            'quantity' => $quantity,
            'unit_price' => $line->unit_price,
            'unit_price_net' => $line->unit_price_net,
            'vat_rate' => $line->vat_rate,
            'reference_price' => $line->reference_price,
            'allocated_amount' => $line->allocated_amount,
            'modifiers' => $line->modifiers,
            'discount_type' => $line->discount_type,
            'discount_value' => $line->discount_value,
            'discount_amount' => $share($line, 'discount_amount'),
            'is_invitation' => $line->is_invitation,
            'discount_reason' => $line->discount_reason,
            'order_discount_amount' => $share($line, 'order_discount_amount'),
            'line_total' => $share($line, 'line_total'),
            'taxable_amount' => $share($line, 'taxable_amount'),
            'sort_order' => $line->sort_order,
        ]);
    }

    /** Parte proporcional de un importe para $units de $of unidades (cuadra al céntimo). */
    private function share(int $amount, int $units, int $of): int
    {
        if ($units >= $of) {
            return $amount;
        }

        return Money::allocate($amount, [$units, $of - $units])[0];
    }
}
