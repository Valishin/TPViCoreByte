<?php

namespace App\Services\Pos;

use App\Enums\CashSessionStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PrintJobKind;
use App\Exceptions\CashSessionRequiredException;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Orders\OrderTotalsCalculator;
use App\Services\Orders\TakeawayOrderService;
use App\Services\Printing\Ticket as Printable;
use App\Services\Tickets\TicketIssuer;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Cobro de un pedido en una sola transacción: ticket con número correlativo,
 * pagos (mixtos, con cambio y propina), cierre del pedido y liberación de la
 * mesa. La impresión del ticket, los justificantes y el cajón (solo si hay
 * efectivo) se encolan para después del commit.
 *
 * Idempotente: la misma `idempotency_key` devuelve el mismo ticket aunque la
 * petición llegue dos veces (doble toque, reintento de red).
 */
class CheckoutService
{
    public function __construct(
        private TicketIssuer $issuer,
        private OrderTotalsCalculator $totals,
        private PrintJobDispatcher $printing,
        private ReceiptFormatter $formatter,
    ) {}

    public static function openSession(): ?CashSession
    {
        return CashSession::query()->where('status', CashSessionStatus::Open)->latest('id')->first();
    }

    /**
     * @param  list<array{method: string, amount: int, tendered?: int|null, tip?: int|null}>  $payments
     * @return array{ticket: Ticket, change: int, replayed: bool}
     */
    /**
     * @param  Customer|null  $recipient  con datos fiscales: factura completa (serie F) en lugar del ticket
     */
    public function checkout(Order $order, User $by, array $payments, string $idempotencyKey, ?bool $print = null, bool $paymentReceipts = false, ?Customer $recipient = null): array
    {
        if ($existing = Ticket::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return ['ticket' => $existing, 'change' => $this->change($existing), 'replayed' => true];
        }

        $session = self::openSession() ?? throw new CashSessionRequiredException;
        $print ??= Setting::current()->print_ticket_on_checkout;

        return DB::transaction(function () use ($order, $by, $payments, $idempotencyKey, $print, $paymentReceipts, $session, $recipient) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Otra petición con la misma clave ha ganado mientras esperábamos el bloqueo.
            if ($existing = Ticket::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return ['ticket' => $existing, 'change' => $this->change($existing), 'replayed' => true];
            }
            if (! $order->isOpen()) {
                throw new LogicException('Este pedido ya está cobrado o cerrado.');
            }
            if ($order->type === OrderType::Delivery) {
                throw new LogicException('Los pedidos a domicilio se cobran al dar salida (ya pagados) o al liquidar al repartidor.');
            }

            $total = $this->totals->apply($order)->total;
            $payments = $this->validate($payments, $total);

            $ticket = $this->issuer->issue($order, $by, $session, $idempotencyKey, $recipient);

            foreach ($payments as $payment) {
                $ticket->payments()->create([
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'tendered' => $payment['tendered'],
                    'change' => $payment['tendered'] !== null ? $payment['tendered'] - $payment['amount'] : null,
                    'tip' => $payment['tip'],
                    'user_id' => $by->id,
                    'cash_session_id' => $session->id,
                    'paid_at' => now(),
                ]);
            }
            if ($ticket->payment_status !== PaymentStatus::Paid) {
                $ticket->update(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()]);
            }

            $this->queuePrinting($ticket->refresh(), $by, $session, $print, $paymentReceipts);
            app(TakeawayOrderService::class)->afterCheckout($order->refresh(), $by);

            return ['ticket' => $ticket, 'change' => $this->change($ticket), 'replayed' => false];
        });
    }

    /** Reimprime un ticket con la marca COPIA. */
    public function reprint(Ticket $ticket, User $by): void
    {
        $printer = PrintJobDispatcher::ticketPrinter() ?? throw new LogicException('No hay ninguna impresora de tickets activa.');
        // En las copias, send_number guarda el nº de copia (el original no lleva).
        $this->printing->dispatch(null, $printer, PrintJobKind::Receipt, $this->formatter->ticket($ticket, $printer->columns, copy: true),
            sendNumber: self::reprintCount($ticket) + 1, by: $by, forTicket: $ticket);
    }

    /** Copias ya reimpresas (sin contar el original). */
    public static function reprintCount(Ticket $ticket): int
    {
        return $ticket->printJobs()->where('kind', PrintJobKind::Receipt)->whereNotNull('send_number')->count();
    }

    public function openDrawer(User $by, ?CashSession $session = null): void
    {
        $printer = PrintJobDispatcher::ticketPrinter() ?? throw new LogicException('No hay ninguna impresora de tickets (cajón) activa.');
        $this->printing->dispatch(null, $printer, PrintJobKind::Drawer, (new Printable)->pulse(), by: $by, session: $session ?? self::openSession());
    }

    private function queuePrinting(Ticket $ticket, User $by, CashSession $session, bool $print, bool $paymentReceipts): void
    {
        $printer = PrintJobDispatcher::ticketPrinter();
        if (! $printer) {
            return; // sin impresora de tickets: el ticket queda emitido igualmente
        }

        if ($print) {
            $this->printing->dispatch(null, $printer, PrintJobKind::Receipt, $this->formatter->ticket($ticket, $printer->columns), by: $by, forTicket: $ticket, session: $session);
        }

        // Cuenta dividida a partes iguales: un justificante por parte (no es factura).
        $payments = $ticket->payments;
        if ($paymentReceipts && $payments->count() > 1) {
            foreach ($payments->values() as $i => $payment) {
                $this->printing->dispatch(null, $printer, PrintJobKind::PaymentReceipt,
                    $this->formatter->paymentReceipt($ticket, $payment, $i + 1, $payments->count()), by: $by, forTicket: $ticket, session: $session);
            }
        }

        // El cajón solo se abre si se ha cobrado algo en efectivo.
        if ($payments->contains(fn ($p) => $p->method === PaymentMethod::Cash)) {
            $this->printing->dispatch(null, $printer, PrintJobKind::Drawer, (new Printable)->pulse(), by: $by, forTicket: $ticket, session: $session);
        }
    }

    /** @return list<array{method: PaymentMethod, amount: int, tendered: int|null, tip: int}> */
    private function validate(array $payments, int $total): array
    {
        $clean = [];
        foreach ($payments as $payment) {
            $method = PaymentMethod::tryFrom($payment['method'] ?? '') ?? throw new InvalidArgumentException('Forma de pago no válida.');
            $amount = (int) ($payment['amount'] ?? 0);
            if ($amount <= 0) {
                throw new InvalidArgumentException('Cada pago debe tener un importe positivo.');
            }
            $tendered = $method === PaymentMethod::Cash && isset($payment['tendered']) ? (int) $payment['tendered'] : null;
            if ($tendered !== null && $tendered < $amount) {
                throw new InvalidArgumentException('Lo entregado es menor que el importe.');
            }
            $tip = (int) ($payment['tip'] ?? 0);
            if ($tip < 0 || ($tip > 0 && $method !== PaymentMethod::Card)) {
                throw new InvalidArgumentException('La propina solo se admite en pagos con tarjeta.');
            }
            $clean[] = ['method' => $method, 'amount' => $amount, 'tendered' => $tendered, 'tip' => $tip];
        }

        $paid = array_sum(array_column($clean, 'amount'));
        if ($paid !== $total) {
            throw new InvalidArgumentException($paid < $total
                ? 'Falta por cobrar '.Format::money($total - $paid).'.'
                : 'Los pagos superan el total en '.Format::money($paid - $total).'.');
        }

        return $clean;
    }

    private function change(Ticket $ticket): int
    {
        return (int) $ticket->payments()->sum('change');
    }
}
