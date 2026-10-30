<?php

namespace App\Models;

use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Serie de numeración. Los contadores van por serie y año
 * (ticket_series_counters) y solo los toca TicketNumberService, con la fila
 * bloqueada. digits: cifras del número (6 por defecto, mínimo 5).
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
            'digits' => 'integer',
        ];
    }

    /** Último número emitido en un año (0 si aún ninguno). */
    public function lastNumber(?int $year = null): int
    {
        return (int) DB::table('ticket_series_counters')->where('ticket_series_id', $this->id)
            ->where('year', $year ?? DocumentNumber::yearOf(now()))->value('last_number');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
