<?php

namespace App\Services\Orders;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Events\Pos\TakeawayOrderChanged;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Pedidos para llevar y a domicilio: alta con cliente, hora prometida y pago
 * previsto, y el estado de reparto (delivery_status). Es el ÚNICO sitio que
 * cambia delivery_status, como OrderService con las mesas.
 *
 *   Pendiente → En cocina (al enviar la comanda) → Listo
 *     → domicilio: En reparto (DispatchService) → Entregado / No entregado
 *     → para llevar: Entregado (al recogerlo, cobrado)
 */
class TakeawayOrderService
{
    public function __construct(
        private OrderService $orders,
        private OrderTotalsCalculator $totals,
    ) {}

    /**
     * @param  array{
     *     customer_id?: int|null, address_id?: int|null,
     *     customer_name?: string|null, customer_phone?: string|null,
     *     asap?: bool, promised_in?: int|null, promised_at?: CarbonInterface|string|null,
     *     payment?: string|null, cash_change_for?: int|null, notes?: string|null,
     * }  $data  payment: efectivo | tarjeta | pagado:<método>
     */
    public function create(OrderType $type, User $by, array $data): Order
    {
        if (! $type->isTakeaway()) {
            throw new InvalidArgumentException('Solo pedidos para llevar o a domicilio.');
        }
        if ($type === OrderType::Delivery && (empty($data['customer_id']) || empty($data['address_id']))) {
            throw new InvalidArgumentException('Un pedido a domicilio necesita cliente y dirección.');
        }

        return DB::transaction(function () use ($type, $by, $data) {
            $order = $this->orders->open($type, $by, attributes: ['notes' => $data['notes'] ?? null]);
            // Sin hora elegida: "Lo antes posible".
            $chosen = isset($data['promised_in']) || isset($data['promised_at']) || array_key_exists('asap', $data);
            $this->fill($order, $chosen ? $data : $data + ['asap' => true]);

            return $this->changed($order);
        });
    }

    /** Cambia cliente, dirección, hora o pago previsto mientras el pedido está abierto. */
    public function update(Order $order, array $data): Order
    {
        $this->assertTakeaway($order);
        if (! $order->isOpen()) {
            throw new LogicException('El pedido ya tiene ticket: no se puede cambiar.');
        }

        return DB::transaction(fn () => $this->changed($this->fill($order, $data)));
    }

    /** La comanda se ha enviado a cocina: Pendiente → En cocina. */
    public function sentToKitchen(Order $order): void
    {
        if ($order->isTakeawayOrDelivery() && $order->delivery_status === DeliveryStatus::Pending) {
            $order->update(['delivery_status' => DeliveryStatus::InKitchen, 'kitchen_at' => now()]);
            $this->changed($order);
        }
    }

    /** En cocina → Listo. */
    public function markReady(Order $order): Order
    {
        $this->assertTakeaway($order);
        $this->assertStatus($order, DeliveryStatus::InKitchen, 'Solo se marca listo un pedido que está en cocina.');
        $order->update(['delivery_status' => DeliveryStatus::Ready, 'ready_at' => now()]);

        return $this->changed($order);
    }

    /**
     * Entregado. Para llevar: listo y ya con ticket (cobrado). Domicilio: en
     * reparto (lo marca el repartidor o la caja al liquidar).
     */
    public function markDelivered(Order $order, User $by): Order
    {
        $this->assertTakeaway($order);
        if ($order->isOpen()) {
            throw new LogicException('Cobra el pedido antes de entregarlo.');
        }
        $from = $order->type === OrderType::Delivery ? DeliveryStatus::OutForDelivery : DeliveryStatus::Ready;
        $this->assertStatus($order, $from, $order->type === OrderType::Delivery
            ? 'Solo se entrega un pedido que está en reparto.'
            : 'Solo se entrega un pedido que está listo.');

        $order->update(['delivery_status' => DeliveryStatus::Delivered, 'delivered_at' => now(), 'delivered_by' => $by->id]);

        return $this->changed($order);
    }

    /** Tras cobrar un pedido para llevar que ya estaba listo: se lo ha llevado. */
    public function afterCheckout(Order $order, User $by): void
    {
        if ($order->type === OrderType::Takeaway && $order->delivery_status === DeliveryStatus::Ready) {
            $this->markDelivered($order->refresh(), $by);
        } elseif ($order->isTakeawayOrDelivery()) {
            $this->changed($order);
        }
    }

    /** Tablero "Pedidos": activos (de cualquier día) y los terminados hoy. */
    public function board(): Builder
    {
        return Order::query()
            ->whereIn('type', [OrderType::Takeaway, OrderType::Delivery])
            ->where('status', '!=', OrderStatus::Cancelled)
            ->where(function (Builder $q) {
                $q->whereNotIn('delivery_status', [DeliveryStatus::Delivered, DeliveryStatus::Failed])
                    ->orWhere('delivered_at', '>=', today())
                    ->orWhere(fn ($q) => $q->where('delivery_status', DeliveryStatus::Failed)->where('updated_at', '>=', today()));
            })
            ->with(['courier', 'deliveryZone', 'tickets'])
            ->orderBy('promised_at');
    }

    /** Hora prometida de "Lo antes posible" según el tiempo estimado de Ajustes. */
    public static function asapTime(OrderType $type, ?Setting $settings = null): CarbonInterface
    {
        $settings ??= Setting::current();

        return now()->addMinutes($type === OrderType::Delivery ? $settings->delivery_minutes : $settings->takeaway_prep_minutes)->startOfMinute();
    }

    public function changed(Order $order): Order
    {
        event(new TakeawayOrderChanged($order));

        return $order;
    }

    private function fill(Order $order, array $data): Order
    {
        // Cliente y dirección (snapshot en el pedido).
        if (array_key_exists('customer_id', $data) || array_key_exists('address_id', $data)) {
            $customer = isset($data['customer_id']) ? Customer::query()->findOrFail($data['customer_id']) : null;
            $address = isset($data['address_id']) ? CustomerAddress::query()->findOrFail($data['address_id']) : null;
            if ($order->type === OrderType::Delivery && (! $customer || ! $address)) {
                throw new InvalidArgumentException('Un pedido a domicilio necesita cliente y dirección.');
            }
            if ($customer) {
                $this->orders->attachCustomer($order, $customer, $address);
            }
        }
        // Para llevar sin ficha: nombre y teléfono sueltos (opcionales).
        if ($order->type === OrderType::Takeaway && ! $order->customer_id) {
            $phone = array_key_exists('customer_phone', $data) ? PhoneNormalizer::normalize($data['customer_phone']) : $order->customer_phone;
            $name = array_key_exists('customer_name', $data) ? (trim((string) $data['customer_name']) ?: null) : $order->customer_name;
            $known = $phone ? Customer::findByPhone($phone) : null;
            if ($known && ! $known->isAnonymized()) {
                $this->orders->attachCustomer($order, $known);
                $order->update(['customer_name' => $name ?? $known->name]);
            } else {
                $order->update(['customer_name' => $name, 'customer_phone' => $phone]);
            }
        }

        // Hora prometida.
        if (! empty($data['asap'])) {
            $order->update(['asap' => true, 'promised_at' => self::asapTime($order->type)]);
        } elseif (isset($data['promised_in'])) {
            $order->update(['asap' => false, 'promised_at' => now()->addMinutes((int) $data['promised_in'])->startOfMinute()]);
        } elseif (isset($data['promised_at'])) {
            $order->update(['asap' => false, 'promised_at' => $data['promised_at']]);
        }

        // Pago previsto: efectivo (con "cambio para"), tarjeta o ya pagado con un método.
        if (array_key_exists('payment', $data) && $data['payment'] !== null) {
            [$plan, $prepaid] = array_pad(explode(':', $data['payment'], 2), 2, null);
            if ($plan === 'pagado') {
                $method = PaymentMethod::tryFrom((string) $prepaid) ?? throw new InvalidArgumentException('Indica con qué se ha pagado.');
                if ($method === PaymentMethod::Cash) {
                    throw new InvalidArgumentException('"Ya pagado" es con tarjeta, Bizum, online u otro.');
                }
                $order->update(['planned_payment_method' => null, 'prepaid_method' => $method, 'cash_change_for' => null]);
            } else {
                $method = PaymentMethod::tryFrom($plan);
                if (! in_array($method, [PaymentMethod::Cash, PaymentMethod::Card], true)) {
                    throw new InvalidArgumentException('Pago previsto: efectivo, tarjeta o ya pagado.');
                }
                $order->update(['planned_payment_method' => $method, 'prepaid_method' => null]);
            }
        }
        if (array_key_exists('cash_change_for', $data)) {
            $order->update(['cash_change_for' => $order->planned_payment_method === PaymentMethod::Cash ? ($data['cash_change_for'] ?: null) : null]);
        }
        if ($order->planned_payment_method !== PaymentMethod::Cash && $order->cash_change_for !== null) {
            $order->update(['cash_change_for' => null]); // "cambio para" solo con efectivo
        }

        $this->totals->apply($order);

        return $order;
    }

    private function assertTakeaway(Order $order): void
    {
        if (! $order->isTakeawayOrDelivery()) {
            throw new InvalidArgumentException('No es un pedido para llevar ni a domicilio.');
        }
    }

    private function assertStatus(Order $order, DeliveryStatus $expected, string $message): void
    {
        if ($order->delivery_status !== $expected) {
            throw new LogicException($message);
        }
    }
}
