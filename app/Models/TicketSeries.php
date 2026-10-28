<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Serie de numeración de tickets. last_number solo lo modifica
 * TicketNumberService, con la fila bloqueada.
 */
#[Unguarded]
class TicketSeries extends Model
{
    use HasFactory;

    protected $table = 'ticket_series';

    protected function casts(): array
    {
        return [
            'is_rectifying' => 'boolean',
            'is_active' => 'boolean',
            'last_number' => 'integer',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
