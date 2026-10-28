<?php

namespace App\Services\Tickets;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registra cobros de un ticket. Admite pagos mixtos y cobros posteriores a la
 * emisión (domicilio cobrado al volver el repartidor). Cuando lo cobrado
 * cubre el total, marca el ticket como pagado (única modificación permitida).
 */
class PaymentService
{
    public function register(
        Ticket $ticket,
        PaymentMethod $method,
        int $amount,
        User $by,
        ?int $tendered = null,
        ?CashSession $session = null,
    ): Payment {
        if ($amount <= 0) {
            throw new InvalidArgumentException('El importe debe ser positivo.');
        }

        return DB::transaction(function () use ($ticket, $method, $amount, $by, $tendered, $session) {
            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $pending = $ticket->total - $ticket->paidAmount();
            if ($amount > $pending) {
                throw new InvalidArgumentException('El importe supera lo pendiente de cobro.');
            }
            if ($tendered !== null && $tendered < $amount) {
                throw new InvalidArgumentException('Lo entregado es menor que el importe.');
            }

            $payment = $ticket->payments()->create([
                'method' => $method,
                'amount' => $amount,
                'tendered' => $tendered,
                'change' => $tendered !== null ? $tendered - $amount : null,
                'user_id' => $by->id,
                'cash_session_id' => $session?->id,
                'paid_at' => now(),
            ]);

            if ($amount === $pending) {
                $ticket->update(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()]);
            }

            return $payment;
        });
    }
}
