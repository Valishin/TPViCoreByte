<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\RectificationMethod;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Exceptions\ImmutableTicketException;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ticket (factura simplificada o rectificativa). INMUTABLE.
 *
 * Una vez creado solo pueden cambiar `payment_status` / `paid_at` (cobro
 * posterior, p. ej. al volver el repartidor) y `status` de emitido a
 * rectificado. Nunca se borra. La base de datos refuerza esta regla con un
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
                if (! ($from === TicketStatus::Issued && $ticket->status === TicketStatus::Rectified)) {
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
            'tax_base' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'vat_breakdown' => 'array',
            'issuer' => 'array',
            'customer' => 'array',
            'delivery_address' => 'array',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function fullNumber(): string
    {
        return sprintf('%s-%06d', $this->series_code, $this->number);
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
        return $this->hasMany(TicketLine::class)->orderBy('sort_order')->orderBy('id');
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

    public function rectifications(): HasMany
    {
        return $this->hasMany(Ticket::class, 'rectified_ticket_id');
    }

    public function fiscalRecords(): HasMany
    {
        return $this->hasMany(FiscalRecord::class);
    }
}
