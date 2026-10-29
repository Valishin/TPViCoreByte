<?php

namespace App\Services\Orders;

use App\Enums\DeliveryStatus;
use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PrinterType;
use App\Enums\PrintJobKind;
use App\Enums\TableStatus;
use App\Exceptions\TableUnavailableException;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Printer;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use App\Services\Pos\KitchenFormatter;
use App\Services\Pos\PrintJobDispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Ciclo de vida de los pedidos. Es el ÚNICO sitio que cambia el estado de
 * las mesas (tables.status).
 */
class OrderService
{
    public function __construct(
        private OrderTotalsCalculator $totals,
        private PrintJobDispatcher $printing,
        private KitchenFormatter $formatter,
    ) {}

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
                $table = $this->lockFreeTable($table->id);
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

    /**
     * Anula una línea (y sus componentes). Si ya se había enviado, el motivo es
     * obligatorio y se imprime una ANULACIÓN en cada impresora a la que fue.
     */
    public function voidLine(OrderLine $line, User $by, ?string $reason = null): OrderLine
    {
        $this->assertRootLine($line);
        if ($line->isVoided()) {
            throw new LogicException('La línea ya está anulada.');
        }
        if ($line->isSent() && blank($reason)) {
            throw new InvalidArgumentException('Indica el motivo: la línea ya se envió a cocina.');
        }

        return DB::transaction(function () use ($line, $by, $reason) {
            $order = $line->order;
            $line->load(['children.modifiers', 'children.product', 'modifiers', 'product']);
            $printable = $line->children->isEmpty() ? collect([$line]) : $line->children;

            OrderLine::query()->whereKey($line->id)->orWhere('parent_line_id', $line->id)
                ->update(['voided_at' => now(), 'void_reason' => $reason ?: 'Sin enviar', 'voided_by' => $by->id]);

            if ($line->isSent()) {
                $printers = Printer::query()->whereIn('id', $printable->pluck('printer_id')->filter())->where('is_active', true)->get()->keyBy('id');
                foreach ($printable->groupBy('printer_id') as $printerId => $lines) {
                    if ($printer = $printers->get($printerId)) {
                        $this->printing->dispatch($order, $printer, PrintJobKind::Void,
                            $this->formatter->void($order, $lines, $reason, $by), $lines->pluck('id')->all(), $line->send_number, $by);
                    }
                }
            }

            $this->totals->apply($order->refresh());

            return $line->refresh();
        });
    }

    /** Cambia cantidad o notas de una línea que aún no se ha enviado. */
    public function updateLine(OrderLine $line, ?int $quantity = null, ?string $notes = null, bool $updateNotes = false): OrderLine
    {
        $this->assertRootLine($line);
        if ($line->isSent() || $line->isVoided()) {
            throw new LogicException('Esta línea ya se envió: anúlala y añádela de nuevo.');
        }
        if ($quantity !== null && $quantity < 1) {
            throw new InvalidArgumentException('La cantidad mínima es 1.');
        }

        $line->update(array_filter([
            'quantity' => $quantity,
        ], fn ($v) => $v !== null) + ($updateNotes ? ['notes' => blank($notes) ? null : $notes] : []));
        $this->totals->apply($line->order->refresh());

        return $line->refresh();
    }

    public function setGuests(Order $order, int $guests): Order
    {
        $this->assertOpen($order);
        $order->update(['guests' => max(1, $guests)]);

        return $order;
    }

    /** Mueve el pedido a otra mesa libre. */
    public function move(Order $order, Table $target): Order
    {
        $this->assertOpen($order);
        if ($order->type !== OrderType::Table) {
            throw new InvalidArgumentException('Solo se pueden mover pedidos de mesa.');
        }
        if ($order->table_id === $target->id) {
            return $order;
        }

        return DB::transaction(function () use ($order, $target) {
            // Bloqueo en orden de id para que dos movimientos cruzados no se bloqueen entre sí.
            $ids = collect([$order->table_id, $target->id])->sort()->values();
            Table::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $target = $this->lockFreeTable($target->id);
            $from = Table::query()->findOrFail($order->table_id);

            $status = $from->status === TableStatus::BillRequested ? TableStatus::BillRequested : TableStatus::Occupied;
            $order->update(['table_id' => $target->id]);
            $target->update(['status' => $status]);
            $this->freeIfEmpty($from);

            return $order->refresh();
        });
    }

    /** Tras un nuevo envío a cocina, una mesa con la cuenta pedida vuelve a "ocupada". */
    public function reopenAfterSend(Order $order): void
    {
        if (! $order->table_id) {
            return;
        }
        $table = Table::query()->whereKey($order->table_id)->lockForUpdate()->first();
        if ($table?->status === TableStatus::BillRequested) {
            $table->update(['status' => TableStatus::Occupied]);
        }
    }

    /** Imprime la cuenta (no válida como factura) y marca la mesa como "cuenta pedida". */
    public function precheck(Order $order, User $by): Order
    {
        $this->assertOpen($order);
        $printer = Printer::query()->where('type', PrinterType::Ticket)->where('is_active', true)->orderBy('id')->first()
            ?? throw new LogicException('No hay ninguna impresora de tickets activa.');

        return DB::transaction(function () use ($order, $printer, $by) {
            $this->totals->apply($order);
            $this->printing->dispatch($order, $printer, PrintJobKind::Precheck, $this->formatter->precheck($order->refresh()), by: $by);

            if ($order->table_id) {
                Table::query()->whereKey($order->table_id)->lockForUpdate()->first()?->update(['status' => TableStatus::BillRequested]);
            }

            return $order;
        });
    }

    /**
     * Cambia el tipo de pedido y RECALCULA el IVA de todas sus líneas
     * (p. ej. alcohol al 21 % si pasa de mesa a para llevar).
     */
    public function changeType(Order $order, OrderType $type, ?Table $table = null): Order
    {
        $this->assertOpen($order);
        if (! Setting::current()->isOrderTypeEnabled($type)) {
            throw new InvalidArgumentException("El tipo de pedido {$type->label()} no está activo en este local.");
        }
        if ($type === OrderType::Table && ! $table && ! $order->table_id) {
            throw new InvalidArgumentException('Elige la mesa.');
        }

        return DB::transaction(function () use ($order, $type, $table) {
            $previousTable = $order->table_id ? Table::query()->find($order->table_id) : null;

            if ($type === OrderType::Table) {
                $tableId = $table?->id ?? $order->table_id;
                if ($tableId !== $order->table_id) {
                    $this->lockFreeTable($tableId)->update(['status' => TableStatus::Occupied]);
                }
                $order->forceFill(['type' => $type, 'table_id' => $tableId, 'delivery_status' => null]);
            } else {
                $order->forceFill([
                    'type' => $type,
                    'table_id' => null,
                    'delivery_status' => $type->isTakeaway() ? ($order->delivery_status ?? DeliveryStatus::Pending) : null,
                    'delivery_fee' => $type === OrderType::Delivery ? $order->delivery_fee : 0,
                ]);
            }
            $order->save();

            if ($previousTable && $previousTable->id !== $order->table_id) {
                $this->freeIfEmpty($previousTable);
            }

            // IVA según el nuevo tipo: cada línea con su producto; el padre de una mitad, como su primera mitad.
            $lines = $order->lines()->with(['product', 'children.product'])->get();
            foreach ($lines as $line) {
                $product = $line->product ?? $line->children->first()?->product;
                if ($product) {
                    $line->forceFill(['vat_rate' => $product->vatRateFor($type)])->saveQuietly();
                }
            }

            $this->totals->apply($order->refresh());

            return $order;
        });
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
                $this->freeIfEmpty(Table::query()->whereKey($order->table_id)->lockForUpdate()->firstOrFail());
            }

            return $order;
        });
    }

    /** Bloquea la mesa y comprueba que está libre (si no, otro camarero se ha adelantado). */
    private function lockFreeTable(int $tableId): Table
    {
        $table = Table::query()->whereKey($tableId)->lockForUpdate()->firstOrFail();

        if (! $table->is_active || $table->status === TableStatus::OutOfService) {
            throw new TableUnavailableException("La mesa {$table->name} está fuera de servicio.");
        }
        if ($table->status !== TableStatus::Free || $table->openOrders()->exists()) {
            throw new TableUnavailableException("La mesa {$table->name} ya está abierta.");
        }

        return $table;
    }

    private function freeIfEmpty(Table $table): void
    {
        if (! $table->openOrders()->exists()) {
            $table->update(['status' => TableStatus::Free]);
        }
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
