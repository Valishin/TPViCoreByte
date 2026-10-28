<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cobro de un ticket. Varios por ticket (pagos mixtos o posteriores a la emisión). */
#[Unguarded]
class Payment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'tendered' => 'integer',
            'change' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}
