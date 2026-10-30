<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\RectificationMethod;
use App\Enums\RectificationReason;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Exceptions\ImmutableTicketException;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Documento fiscal: ticket (factura simplificada), factura completa, factura de
 * canje y sus rectificativas (ver TicketType). INMUTABLE.
 *
 * Una vez creado solo pueden cambiar `payment_status` / `paid_at` (cobro
 * posterior, p. ej. al volver el repartidor) y `status` de emitido a
 * rectificado o a canjeado. Nunca se borra. La base de datos refuerza esta regla con un
 * trigger (ver migración create_ticket_tables).
 */
#[Unguarded]
class Ticket extends Model
{
    use HasFactory;

    /** Columnas que pueden cambiar tras la emisión. */
    public const MUTABLE = ['payment_status', 'paid_at', 'status', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (Ticket $ticket) {
            $forbidden = array_diff(array_keys($ticket->getDirty()), self::MUTABLE);
            if ($forbidden !== []) {
                throw new ImmutableTicketException(
                    "El ticket {$ticket->fullNumber()} es inmutable (intentado cambiar: ".implode(', ', $forbidden).'). Emite un rectificativo.'
                );
            }

            if ($ticket->isDirty('status')) {
                $from = TicketStatus::from($ticket->getRawOriginal('status'));
                if (! ($from === TicketStatus::Issued && in_array($ticket->status, [TicketStatus::Rectified, TicketStatus::Exchanged], true))) {
                    throw new ImmutableTicketException("Transición de estado no permitida: {$from->value} → {$ticket->status->value}");
                }
            }
        });

        static::deleting(function (Ticket $ticket) {
            throw new ImmutableTicketException("El ticket {$ticket->fullNumber()} no se puede borrar.");
        });
    }

    protected function casts(): array
    {
        return [
            'type' => TicketType::class,
            'status' => TicketStatus::class,
            'order_type' => OrderType::class,
            'rectification_method' => RectificationMethod::class,
            'discount_type' => DiscountType::class,
            'payment_status' => PaymentStatus::class,
            'number' => 'integer',
            'subtotal' => 'integer',
            'lines_discount' => 'integer',
            'discount_value' => 'integer',
            'discount_amount' => 'integer',
            'delivery_fee' => 'integer',
            'delivery_fee_vat_rate' => 'integer',
            'year' => 'integer',
            'tax_base' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'vat_breakdown' => 'array',
            'issuer' => 'array',
            'customer' => 'array',
            'delivery_address' => 'array',
            'recipient' => 'array',
            'operation_date' => 'date',
            'rectified_amounts' => 'array',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** Número completo tal como se emitió (snapshot: T-26-000123). Ver App\Support\DocumentNumber. */
    public function fullNumber(): string
    {
        return $this->full_number ?? DocumentNumber::format($this->series_code, $this->year ?? DocumentNumber::yearOf($this->issued_at), $this->number);
    }

    /** Búsqueda por número (ver whereNumber), primero los del año actual. */
    public function scopeSearchNumber(Builder $query, string $search): Builder
    {
        return $query->whereNumber($search)
            ->orderByRaw('(year = ?) desc', [DocumentNumber::yearOf(now())])
            ->orderByDesc('year');
    }

    /**
     * Filtro por número: completo (T-26-000123), serie y cifras (T-123), año
     * y cifras (26-123) o solo las cifras (123, de cualquier serie y año).
     */
    public function scopeWhereNumber(Builder $query, string $search): Builder
    {
        $s = strtoupper(preg_replace('/\s+/', '', $search));
        $match = match (true) {
            (bool) preg_match('/^([A-Z]+)-(\d{2})-(\d+)$/', $s, $m) => ['series_code' => $m[1], 'year' => 2000 + (int) $m[2], 'number' => (int) $m[3]],
            (bool) preg_match('/^(\d{2})-(\d+)$/', $s, $m) => ['year' => 2000 + (int) $m[1], 'number' => (int) $m[2]],
            (bool) preg_match('/^([A-Z]+)-?(\d+)$/', $s, $m) => ['series_code' => $m[1], 'number' => (int) $m[2]],
            (bool) preg_match('/^\d+$/', $s) => ['number' => (int) $s],
            default => null,
        };
        if ($match === null) {
            return $query->whereRaw('false');
        }

        return $query->where($match);
    }

    public function paidAmount(): int
    {
        return (int) $this->payments()->sum('amount');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(TicketSeries::class, 'ticket_series_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function customerModel(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function lines(): HasMany
    {
        // Orden de árbol: cada línea padre seguida de sus hijas.
        return $this->hasMany(TicketLine::class)->orderByRaw(
            '(select p.sort_order from ticket_lines p where p.id = coalesce(ticket_lines.parent_line_id, ticket_lines.id)), '
            .'coalesce(ticket_lines.parent_line_id, ticket_lines.id), ticket_lines.parent_line_id nulls first, ticket_lines.sort_order, ticket_lines.id'
        );
    }

    public function rootLines(): HasMany
    {
        return $this->lines()->whereNull('parent_line_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function rectifiedTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'rectified_ticket_id');
    }

    /** Factura completa (canje) que sustituye a este ticket, si la hay. */
    public function exchangedBy(): HasOne
    {
        return $this->hasOne(Ticket::class, 'replaces_ticket_id');
    }

    /** Ticket que sustituye esta factura de canje (art. 15.6). */
    public function replacedTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'replaces_ticket_id');
    }

    public function isFullInvoice(): bool
    {
        return $this->type->isFullInvoice();
    }

    public function rectificationReason(): ?RectificationReason
    {
        return RectificationReason::tryFrom((string) $this->rectification_reason_code);
    }

    /** TipoFactura de VERI*FACTU (F1, F2, F3, R1-R5). */
    public function verifactuType(): string
    {
        return $this->type->verifactuType($this->rectificationReason());
    }

    public function emails(): HasMany
    {
        return $this->hasMany(InvoiceEmail::class)->latest('id');
    }

    public function rectifications(): HasMany
    {
        return $this->hasMany(Ticket::class, 'rectified_ticket_id');
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function fiscalRecords(): HasMany
    {
        return $this->hasMany(FiscalRecord::class);
    }
}
