<?php

namespace App\Services\Cash;

use App\Enums\AuthorizationAction;
use App\Enums\CashMovementType;
use App\Enums\DeliveryStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PrintJobKind;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\UserRole;
use App\Exceptions\CashSessionRequiredException;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CourierSettlement;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Orders\TakeawayOrderService;
use App\Services\Pos\AuthorizationService;
use App\Services\Pos\CheckoutService;
use App\Services\Pos\PrintJobDispatcher;
use App\Services\Pos\ReceiptFormatter;
use App\Services\Tickets\PaymentService;
use App\Services\Tickets\RectificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Repartidores en caja:
 *  - Fondo de cambio: efectivo que sale del cajón al empezar (no es venta).
 *  - Liquidación: cobra cada ticket con su método real (cobro posterior a la
 *    emisión, en la caja abierta), devuelve el fondo y compara lo entregado
 *    con lo esperado (efectivo cobrado + fondo).
 *  - No entregado: rectificativo total por diferencias, sin devolución.
 */
class CourierService
{
    public function __construct(
        private PaymentService $payments,
        private RectificationService $rectifications,
        private AuthorizationService $authorization,
        private TakeawayOrderService $takeaway,
        private PrintJobDispatcher $printing,
        private ReceiptFormatter $formatter,
    ) {}

    /** Domicilios de un repartidor aún sin liquidar (en reparto o entregados). */
    public static function unsettled(User $courier): Builder
    {
        return Order::query()
            ->where('type', OrderType::Delivery)
            ->where('courier_id', $courier->id)
            ->whereNull('courier_settlement_id')
            ->whereIn('delivery_status', [DeliveryStatus::OutForDelivery, DeliveryStatus::Delivered])
            ->with(['tickets.payments'])
            ->orderBy('dispatched_at');
    }

    /** Fondo entregado y aún no devuelto. */
    public static function outstandingFloat(User $courier): int
    {
        return -(int) CashMovement::query()->where('courier_id', $courier->id)
            ->where('type', CashMovementType::CourierFloat)->whereNull('courier_settlement_id')->sum('amount');
    }

    public static function ticketOf(Order $order): ?Ticket
    {
        return $order->tickets->where('type', TicketType::Simplified)->sortByDesc('id')->first();
    }

    /** Lo que falta por cobrar del ticket (0 si ya pagado o rectificado). */
    public static function pendingAmount(?Ticket $ticket): int
    {
        if (! $ticket || $ticket->status === TicketStatus::Rectified || $ticket->payment_status === PaymentStatus::Paid) {
            return 0;
        }

        return $ticket->total - $ticket->paidAmount();
    }

    /** @return list<array<string, mixed>> por repartidor activo */
    public function overview(): array
    {
        return User::query()->where('role', UserRole::Courier)->where('is_active', true)->orderBy('name')->get()
            ->map(function (User $courier) {
                $orders = self::unsettled($courier)->get();
                $float = self::outstandingFloat($courier);
                $cash = $orders->sum(fn (Order $o) => $o->planned_payment_method === PaymentMethod::Cash ? self::pendingAmount(self::ticketOf($o)) : 0);
                $other = $orders->sum(fn (Order $o) => $o->planned_payment_method !== PaymentMethod::Cash ? self::pendingAmount(self::ticketOf($o)) : 0);

                return [
                    'courier' => ['id' => $courier->id, 'name' => $courier->name, 'color' => $courier->color, 'initials' => $courier->initials()],
                    'float' => $float,
                    'orders' => $orders,
                    'expected_cash' => $cash + $float,  // lo que debe entregar si todo se cobra como estaba previsto
                    'expected_other' => $other,
                ];
            })->all();
    }

    public function giveFloat(User $courier, int $amount, User $by): CashMovement
    {
        $this->assertCourier($courier);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Indica el importe del fondo.');
        }
        $session = $this->session();

        return CashMovement::create([
            'cash_session_id' => $session->id,
            'type' => CashMovementType::CourierFloat,
            'amount' => -$amount,
            'courier_id' => $courier->id,
            'user_id' => $by->id,
        ]);
    }

    /**
     * @param  array<int, list<array{method: string, amount: int}>>  $collected  order_id => cobros reales (mixto si hay varios)
     * @param  list<int>|null  $orderIds  pedidos a liquidar (null = todos los suyos sin liquidar)
     */
    public function settle(User $courier, User $by, array $collected = [], ?int $handedCash = null, ?array $orderIds = null): CourierSettlement
    {
        $this->assertCourier($courier);
        $session = $this->session();

        $settlement = DB::transaction(function () use ($courier, $by, $collected, $handedCash, $orderIds, $session) {
            $query = self::unsettled($courier)->lockForUpdate();
            if ($orderIds !== null) {
                $query->whereIn('id', $orderIds);
            }
            /** @var Collection<int, Order> $orders */
            $orders = $query->get();
            if ($orders->isEmpty() && self::outstandingFloat($courier) === 0) {
                throw new LogicException('Este repartidor no tiene nada pendiente de liquidar.');
            }

            $totals = ['cash' => 0, 'card' => 0, 'other' => 0];
            foreach ($orders as $order) {
                $ticket = self::ticketOf($order);
                $pending = self::pendingAmount($ticket);
                if ($pending > 0) {
                    $rows = $collected[$order->id] ?? [['method' => ($order->planned_payment_method ?? PaymentMethod::Cash)->value, 'amount' => $pending]];
                    if (array_sum(array_column($rows, 'amount')) !== $pending) {
                        throw new InvalidArgumentException("El cobro del ticket {$ticket->fullNumber()} no cuadra con su importe.");
                    }
                    foreach ($rows as $row) {
                        $method = PaymentMethod::from($row['method']);
                        $this->payments->register($ticket, $method, (int) $row['amount'], $by, session: $session);
                        $key = match ($method) {
                            PaymentMethod::Cash => 'cash', PaymentMethod::Card => 'card', default => 'other'
                        };
                        $totals[$key] += (int) $row['amount'];
                    }
                }
                if ($order->delivery_status === DeliveryStatus::OutForDelivery) {
                    $this->takeaway->markDelivered($order, $by); // lo marca la caja al liquidar
                }
            }

            // Devolución del fondo.
            $floats = CashMovement::query()->where('courier_id', $courier->id)
                ->where('type', CashMovementType::CourierFloat)->whereNull('courier_settlement_id')->lockForUpdate()->get();
            $float = -(int) $floats->sum('amount');
            $expected = $totals['cash'] + $float;

            $settlement = CourierSettlement::create([
                'courier_id' => $courier->id,
                'cash_session_id' => $session->id,
                'settled_by' => $by->id,
                'float_amount' => $float,
                'cash_collected' => $totals['cash'],
                'card_collected' => $totals['card'],
                'other_collected' => $totals['other'],
                'expected_cash' => $expected,
                'handed_cash' => $handedCash ?? $expected,
                'difference' => ($handedCash ?? $expected) - $expected,
            ]);
            if ($float > 0) {
                CashMovement::create([
                    'cash_session_id' => $session->id, 'type' => CashMovementType::CourierFloatReturn, 'amount' => $float,
                    'courier_id' => $courier->id, 'courier_settlement_id' => $settlement->id, 'user_id' => $by->id,
                ]);
                CashMovement::query()->whereKey($floats->pluck('id'))->update(['courier_settlement_id' => $settlement->id]);
            }
            Order::query()->whereKey($orders->pluck('id'))->update(['courier_settlement_id' => $settlement->id]);

            return $settlement;
        });

        if ($printer = PrintJobDispatcher::ticketPrinter()) {
            $this->printing->dispatch(null, $printer, PrintJobKind::Settlement, $this->formatter->courierSettlement($settlement->load('orders.tickets', 'courier', 'settledBy')), by: $by, session: $session);
        }
        foreach ($settlement->orders as $order) {
            $this->takeaway->changed($order);
        }

        return $settlement;
    }

    /**
     * No entregado (no está, rechaza, dirección errónea…): con autorización,
     * rectificativo total por diferencias SIN devolución (no se cobró). El
     * pedido queda como no entregado y fuera de lo pendiente de liquidar.
     */
    public function failDelivery(Order $order, User $by, string $reason, ?string $authorizationToken = null): Ticket
    {
        if ($order->type !== OrderType::Delivery || $order->delivery_status !== DeliveryStatus::OutForDelivery) {
            throw new LogicException('Solo un domicilio en reparto puede quedar como no entregado.');
        }
        if (blank($reason)) {
            throw new InvalidArgumentException('Indica el motivo.');
        }
        $ticket = self::ticketOf($order->load('tickets'));
        if (! $ticket || $ticket->payment_status === PaymentStatus::Paid) {
            throw new LogicException('Este pedido ya estaba pagado: anúlalo con un rectificativo con devolución.');
        }

        return DB::transaction(function () use ($order, $by, $reason, $authorizationToken, $ticket) {
            $this->authorization->require(AuthorizationAction::FailedDelivery, $by, $authorizationToken, $order, $reason);
            $rectifying = $this->rectifications->byDifferences($ticket, $by, "No entregado: {$reason}", refund: false);
            $order->update(['delivery_status' => DeliveryStatus::Failed, 'delivery_failed_reason' => $reason, 'delivered_by' => $by->id]);
            $this->takeaway->changed($order);

            return $rectifying;
        });
    }

    private function session(): CashSession
    {
        return CheckoutService::openSession() ?? throw new CashSessionRequiredException;
    }

    private function assertCourier(User $courier): void
    {
        if ($courier->role !== UserRole::Courier) {
            throw new InvalidArgumentException('No es un repartidor.');
        }
    }
}
