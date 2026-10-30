<?php

namespace App\Services\Tickets;

use App\Enums\AuthorizationAction;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Exceptions\CashSessionRequiredException;
use App\Models\Ticket;
use App\Models\TicketAnnulment;
use App\Models\User;
use App\Services\Pos\AuthorizationService;
use App\Services\Pos\CheckoutService;
use App\Services\Verifactu\FiscalRecordGenerator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Anulación de un documento emitido por error, sin operación real (ticket
 * duplicado, de prueba, venta que no llegó a producirse): RRSIF 11.1 y FAQ 17.2.d
 * y 11 de la AEAT. Si la venta existió no se anula: se rectifica.
 *
 * Genera el registro de facturación de anulación (encadenado, en la misma
 * transacción), deja el documento en estado "anulado" (no cuenta en ventas ni
 * IVA) y, si se había cobrado, devuelve los cobros en la caja abierta.
 */
class AnnulmentService
{
    public function __construct(private AuthorizationService $authorization, private FiscalRecordGenerator $fiscal) {}

    public function annul(Ticket $ticket, User $by, string $reason, ?string $authorizationToken = null, bool $authorized = false): TicketAnnulment
    {
        if (blank($reason)) {
            throw new InvalidArgumentException('Indica el motivo.');
        }

        return DB::transaction(function () use ($ticket, $by, $reason, $authorizationToken, $authorized) {
            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $this->assertAnnullable($ticket);
            if (! $authorized) {
                $this->authorization->require(AuthorizationAction::VoidTicket, $by, $authorizationToken, $ticket, $reason);
            }

            $record = $this->fiscal->anulacion($ticket);
            $annulment = TicketAnnulment::create(['ticket_id' => $ticket->id, 'reason' => $reason, 'annulled_by' => $by->id, 'fiscal_record_id' => $record?->id]);
            $this->refundPayments($ticket, $by);
            $ticket->update(['status' => TicketStatus::Annulled]);

            return $annulment;
        });
    }

    /** @throws LogicException con el motivo legible */
    public function assertAnnullable(Ticket $ticket): void
    {
        if (! in_array($ticket->type, [TicketType::Simplified, TicketType::Invoice], true)) {
            throw new LogicException('Solo se anulan tickets y facturas emitidos por error (no rectificativos ni facturas de canje).');
        }
        if ($ticket->status !== TicketStatus::Issued || $ticket->rectifications()->exists() || $ticket->exchanges()->exists()) {
            throw new LogicException('Este documento ya tiene rectificativos, se canjeó o está anulado: la venta existió, se corrige con un rectificativo.');
        }
    }

    /** Los cobros del documento anulado se devuelven (pagos negativos) en la caja abierta. */
    private function refundPayments(Ticket $ticket, User $by): void
    {
        $byMethod = $ticket->payments()->get()->groupBy(fn ($p) => $p->method->value)->map->sum('amount')->filter(fn ($amount) => $amount > 0);
        if ($byMethod->isEmpty()) {
            return;
        }
        $session = CheckoutService::openSession() ?? throw new CashSessionRequiredException;
        foreach ($ticket->payments()->get()->unique(fn ($p) => $p->method->value) as $payment) {
            $ticket->payments()->create([
                'method' => $payment->method, 'amount' => -$byMethod[$payment->method->value],
                'user_id' => $by->id, 'cash_session_id' => $session->id, 'paid_at' => now(),
            ]);
        }
    }
}
