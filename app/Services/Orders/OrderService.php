<?php

namespace App\Services\Orders;

use App\Enums\DeliveryStatus;
use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Ciclo de vida de los pedidos. Es el ÚNICO sitio que cambia el estado de
 * las mesas (tables.status).
 */
class OrderService
{
    public function __construct(private OrderTotalsCalculator $totals) {}

    /** Abre un pedido. Para tipo mesa, ocupa la mesa. */
    public function open(OrderType $type, User $by, ?Table $table = null, ?int $guests = null, array $attributes = []): Order
    {
        if (! Setting::current()->isOrderTypeEnabled($type)) {
            throw new InvalidArgumentException("El tipo de pedido {$type->label()} no está activo en este local.");
        }
        if (($type === OrderType::Table) !== ($table !== null)) {
            throw new InvalidArgumentException('Los pedidos de mesa necesitan mesa, y solo ellos.');
        }

        return DB::transaction(function () use ($type, $by, $table, $guests, $attributes) {
            if ($table) {
                $table = Table::query()->whereKey($table->id)->lockForUpdate()->firstOrFail();
                if (! $table->is_active || $table->status === TableStatus::OutOfService) {
                    throw new LogicException("La mesa {$table->name} está fuera de servicio.");
                }
                $table->update(['status' => TableStatus::Occupied]);
            }

            return Order::create($attributes + [
                'type' => $type,
                'status' => OrderStatus::Open,
                'table_id' => $table?->id,
                'guests' => $guests,
                'opened_by' => $by->id,
                'opened_at' => now(),
                'delivery_status' => $type->isTakeaway() ? DeliveryStatus::Pending : null,
            ]);
        });
    }

    /**
     * Asigna cliente al pedido y COPIA su nombre, teléfono y dirección
     * (snapshot): cambiar luego la ficha del cliente no altera el pedido.
     */
    public function attachCustomer(Order $order, Customer $customer, ?CustomerAddress $address = null): Order
    {
        if ($address && $address->customer_id !== $customer->id) {
            throw new InvalidArgumentException('La dirección no pertenece al cliente.');
        }
        if ($order->type === OrderType::Delivery && ! $address) {
            throw new InvalidArgumentException('Un pedido a domicilio necesita dirección.');
        }

        $zone = $address?->deliveryZone;

        $order->update([
            'customer_id' => $customer->id,
            'customer_address_id' => $address?->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'delivery_address' => $address?->snapshot(),
            'delivery_zone_id' => $zone?->id,
            'delivery_fee' => $order->type === OrderType::Delivery ? ($zone?->delivery_fee ?? 0) : 0,
        ]);
        $this->totals->apply($order);

        return $order;
    }

    public function discountLine(OrderLine $line, DiscountType $type, int $value, User $by, ?string $reason = null): OrderLine
    {
        $this->assertRootLine($line);
        $line->update([
            'discount_type' => $type,
            'discount_value' => $value,
            'is_invitation' => false,
            'discount_reason' => $reason,
            'discounted_by' => $by->id,
        ]);
        $this->totals->apply($line->order);

        return $line->refresh();
    }

    public function inviteLine(OrderLine $line, User $by, ?string $reason = null): OrderLine
    {
        $this->assertRootLine($line);
        $line->update([
            'discount_type' => null,
            'discount_value' => null,
            'is_invitation' => true,
            'discount_reason' => $reason ?? 'Invitación',
            'discounted_by' => $by->id,
        ]);
        $this->totals->apply($line->order);

        return $line->refresh();
    }

    public function discountOrder(Order $order, DiscountType $type, int $value, User $by, ?string $reason = null): Order
    {
        $this->assertOpen($order);
        $order->update([
            'discount_type' => $type,
            'discount_value' => $value,
            'discount_reason' => $reason,
            'discounted_by' => $by->id,
        ]);
        $this->totals->apply($order);

        return $order->refresh();
    }

    public function voidLine(OrderLine $line, User $by, string $reason): OrderLine
    {
        $this->assertOpen($line->order);
        $now = now();
        // Se anulan también sus hijas (componentes de menú / mitades).
        OrderLine::query()->whereKey($line->id)->orWhere('parent_line_id', $line->id)
            ->update(['voided_at' => $now, 'void_reason' => $reason, 'voided_by' => $by->id]);
        $this->totals->apply($line->order->refresh());

        return $line->refresh();
    }

    /** Cierra el pedido (lo llama TicketIssuer al emitir el ticket) y libera la mesa. */
    public function close(Order $order): Order
    {
        return $this->finish($order, OrderStatus::Closed);
    }

    public function cancel(Order $order): Order
    {
        return $this->finish($order, OrderStatus::Cancelled);
    }

    private function finish(Order $order, OrderStatus $status): Order
    {
        $this->assertOpen($order);

        return DB::transaction(function () use ($order, $status) {
            $order->update(['status' => $status, 'closed_at' => now()]);

            if ($order->table_id) {
                $table = Table::query()->whereKey($order->table_id)->lockForUpdate()->firstOrFail();
                if (! $table->openOrders()->exists()) {
                    $table->update(['status' => TableStatus::Free]);
                }
            }

            return $order;
        });
    }

    private function assertOpen(Order $order): void
    {
        if (! $order->isOpen()) {
            throw new LogicException('El pedido no está abierto.');
        }
    }

    private function assertRootLine(OrderLine $line): void
    {
        $this->assertOpen($line->order);
        if ($line->parent_line_id !== null) {
            throw new InvalidArgumentException('Los descuentos se aplican a la línea completa, no a un componente.');
        }
    }
}
