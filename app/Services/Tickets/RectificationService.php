<?php

namespace App\Services\Tickets;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PrintJobKind;
use App\Enums\RectificationMethod;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Exceptions\CashSessionRequiredException;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Models\User;
use App\Services\Pos\CheckoutService;
use App\Services\Pos\PrintJobDispatcher;
use App\Services\Pos\ReceiptFormatter;
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
     */
    public function byDifferences(Ticket $original, User $by, string $reason, ?array $quantities = null, ?PaymentMethod $refundMethod = null, bool $print = true): Ticket
    {
        return $this->rectify($original, $by, $reason, RectificationMethod::Differences, $quantities, $refundMethod, $print);
    }

    /**
     * @param  array<int, int>  $removed  ticket_line_id (raíz) => unidades que se quitan del ticket corregido
     */
    public function bySubstitution(Ticket $original, User $by, string $reason, array $removed, ?PaymentMethod $refundMethod = null, bool $print = false): Ticket
    {
        return $this->rectify($original, $by, $reason, RectificationMethod::Substitution, $removed, $refundMethod, $print);
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

    private function rectify(Ticket $original, User $by, string $reason, RectificationMethod $method, ?array $quantities, ?PaymentMethod $refundMethod, bool $print): Ticket
    {
        if ($original->type !== TicketType::Simplified) {
            throw new LogicException('Solo se pueden rectificar facturas simplificadas.');
        }
        if (blank($reason)) {
            throw new InvalidArgumentException('Indica el motivo.');
        }
        if ($method === RectificationMethod::Substitution && $original->rectifications()->exists()) {
            throw new LogicException('Este ticket ya tiene rectificativos: por sustitución solo se puede rectificar una vez.');
        }
        $session = CheckoutService::openSession() ?? throw new CashSessionRequiredException;

        return DB::transaction(function () use ($original, $by, $reason, $method, $quantities, $refundMethod, $print, $session) {
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
            if (array_sum($affected) === 0) {
                throw new InvalidArgumentException('Elige qué anular.');
            }

            $settings = Setting::current();
            $series = $settings->rectifyingTicketSeries ?? throw new LogicException('No hay serie de rectificativos.');

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
            if ($deliveryFee) {
                $rate = $settings->delivery_fee_vat_rate;
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

            $rectifying = Ticket::create([
                'ticket_series_id' => $series->id,
                'series_code' => $series->code,
                'number' => $this->numbers->next($series),
                'type' => TicketType::Rectifying,
                'status' => TicketStatus::Issued,
                'order_id' => $original->order_id,
                'order_type' => $original->order_type,
                'cash_session_id' => $session->id,
                'issued_by' => $by->id,
                'issued_at' => now(),
                'rectified_ticket_id' => $original->id,
                'rectification_method' => $method,
                'rectification_reason' => $reason,
                'rectified_amounts' => $method === RectificationMethod::Substitution
                    ? ['base' => $original->tax_base, 'tax' => $original->tax_amount, 'total' => $original->total] : null,
                'subtotal' => $subtotal,
                'lines_discount' => $linesDiscount,
                'discount_type' => $original->discount_type,
                'discount_value' => $original->discount_value,
                'discount_amount' => $orderDiscount,
                'discount_reason' => $original->discount_reason,
                'delivery_fee' => $deliveryFee,
                'tax_base' => array_sum(array_column($breakdown, 'base')),
                'tax_amount' => array_sum(array_column($breakdown, 'tax')),
                'total' => $total,
                'vat_breakdown' => $breakdown,
                'issuer' => $settings->issuerSnapshot(),
                'customer_id' => $original->customer_id,
                'customer' => $original->customer,
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
            if ($refund < 0) {
                $rectifying->payments()->create([
                    'method' => $refundMethod ?? $original->payments()->orderByDesc('amount')->first()?->method ?? PaymentMethod::Cash,
                    'amount' => $refund,
                    'user_id' => $by->id,
                    'cash_session_id' => $session->id,
                    'paid_at' => now(),
                ]);
            }

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
