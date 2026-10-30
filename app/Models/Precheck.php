<?php

namespace App\Models;

use App\Exceptions\ImmutableTicketException;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precuenta impresa ("la cuenta"), conservada de forma inalterable con su
 * contenido completo (LGT 29.2.j; FAQ 11 de la AEAT). No es una factura: no
 * genera registro de facturación.
 */
#[Unguarded]
class Precheck extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableTicketException('Las precuentas impresas no se modifican.'));
        static::deleting(fn () => throw new ImmutableTicketException('Las precuentas impresas no se borran.'));
    }

    protected function casts(): array
    {
        return ['content' => 'array', 'total' => 'integer', 'printed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
