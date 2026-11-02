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
use App\Models\Precheck;
use App\Models\Printer;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use App\Services\Pos\KitchenFormatter;
use App\Services\Pos\PrintJobDispatcher;
use App\Services\Pos\ReceiptFormatter;
use Illuminate\Support\Collection;
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
        private ReceiptFormatter $receipts,
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

    /**
     * Mueve el pedido a otra mesa libre. Con mesas unidas, se mueve SOLO la
     * mesa principal: las demás siguen unidas al pedido y la antigua principal
     * queda libre (si no tiene otras cuentas).
     */
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
            $order->update(['table_id' => $target->id]);   // Order mueve también la fila principal de order_tables
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
        foreach ($this->lockGroupTables($order) as $table) {
            if ($table->status === TableStatus::BillRequested) {
                $table->update(['status' => TableStatus::Occupied]);
            }
        }
    }

    /** Imprime la cuenta (no válida como factura) y marca la mesa como "cuenta pedida". */
    public function precheck(Order $order, User $by): Order
    {
        $this->assertOpen($order);
        $printer = Printer::query()->where('type', PrinterType::Ticket)->where('is_active', true)->orderBy('id')->first()
            ?? throw new LogicException('No hay ninguna impresora de tickets activa.');

        return DB::transaction(function () use ($order, $printer, $by) {
            $totals = $this->totals->apply($order);
            $printable = $this->receipts->precheck($order->refresh(), $totals->vatBreakdown, $by, $printer->columns ?: 48);
            $this->printing->dispatch($order, $printer, PrintJobKind::Precheck, $printable, by: $by);
            // Precuenta conservada de forma inalterable (LGT 29.2.j; FAQ 11 AEAT): no es factura ni genera registro.
            Precheck::create([
                'order_id' => $order->id,
                'printed_by' => $by->id,
                'total' => (int) $order->total,
                'content' => [
                    'printed' => $printable->operations(),
                    'lines' => $order->lines()->whereNull('voided_at')->get(['id', 'parent_line_id', 'name', 'variant_name', 'quantity', 'line_total', 'discount_amount', 'vat_rate'])->toArray(),
                    'subtotal' => (int) $order->subtotal, 'discount_amount' => (int) $order->discount_amount, 'total' => (int) $order->total,
                    'vat_breakdown' => $totals->vatBreakdown,
                ],
                'printed_at' => now(),
            ]);

            foreach ($this->lockGroupTables($order) as $table) {
                $table->update(['status' => TableStatus::BillRequested]);
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
            $previousGroup = $order->table_id ? $this->lockGroupTables($order) : collect();

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
                $previousGroup->each(fn (Table $t) => $this->freeIfEmpty($t));
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
            $tables = $this->lockGroupTables($order);
            $order->update(['status' => $status, 'closed_at' => now()]);   // sus mesas dejan el pedido (Order)

            // Todas las mesas del grupo quedan libres (salvo las que tengan otra cuenta abierta).
            $tables->each(fn (Table $t) => $this->freeIfEmpty($t));

            return $order;
        });
    }

    /**
     * Une mesas al pedido (mesas unidas).
     *
     *  - Mesa libre: pasa a formar parte del pedido.
     *  - Mesa ocupada: las líneas de la cuenta (o cuentas) elegida(s) —enviadas
     *    y pendientes, con todo lo que cuelga de ellas— pasan a este pedido, se
     *    suman los comensales y su pedido queda «unido» (sin ticket). Las líneas
     *    ya enviadas NO se reimprimen. La mesa entra en el grupo solo si se unen
     *    todas sus cuentas; si le quedan otras, sigue con ellas.
     *  - No se pueden unir mesas fuera de servicio, de otro grupo ni con un
     *    descuento global en la cuenta (está repartido entre sus líneas).
     *
     * Bloquea (en orden de id) el pedido, todas las mesas implicadas y los
     * pedidos que se unen: dos camareros uniendo a la vez no se pisan.
     *
     * @param  list<int>  $tableIds
     * @param  array<int, list<int>|'all'>  $accounts  mesa => cuentas (pedidos) que se unen, si tiene varias
     * @return array{order: Order, joined: list<Table>, merged: list<Order>}
     */
    public function joinTables(Order $order, array $tableIds, User $by, array $accounts = []): array
    {
        $tableIds = array_values(array_unique(array_map('intval', $tableIds)));
        if ($tableIds === []) {
            throw new InvalidArgumentException('Elige las mesas que quieres unir.');
        }

        $result = DB::transaction(function () use ($order, $tableIds, $by, $accounts) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($order);
            if ($order->type !== OrderType::Table) {
                throw new InvalidArgumentException('Solo se pueden unir mesas a un pedido de mesa.');
            }
            $groupIds = $order->activeTableLinks()->pluck('table_id')->all();
            $tables = Table::query()->whereIn('id', array_merge($groupIds, $tableIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $main = $tables->get($order->table_id);
            $candidates = Order::query()->whereIn('table_id', $tableIds)->where('status', OrderStatus::Open)
                ->orderBy('id')->lockForUpdate()->get()->groupBy('table_id');

            $plan = [];
            foreach ($tableIds as $id) {
                $table = $tables->get($id) ?? throw new InvalidArgumentException('Mesa no encontrada.');
                if (in_array($id, $groupIds, true)) {
                    throw new InvalidArgumentException("La mesa {$table->name} ya forma parte de esta mesa.");
                }
                if (! $table->is_active || $table->status === TableStatus::OutOfService) {
                    throw new TableUnavailableException("La mesa {$table->name} está fuera de servicio.");
                }
                if ($link = $table->groupLink()->with('order.table')->first()) {
                    throw new TableUnavailableException("La mesa {$table->name} ya está unida a la mesa {$link->order->table->name}.");
                }
                $open = $candidates->get($id, collect());
                $choice = $accounts[$id] ?? $accounts[(string) $id] ?? ($open->count() <= 1 ? 'all' : null);
                if ($choice === null) {
                    throw new InvalidArgumentException("La mesa {$table->name} tiene {$open->count()} cuentas: elige cuál unir.");
                }
                $chosen = $choice === 'all' ? $open : $open->whereIn('id', array_map('intval', (array) $choice));
                if ($choice !== 'all' && $chosen->count() !== count((array) $choice)) {
                    throw new InvalidArgumentException("Esa cuenta ya no está abierta en la mesa {$table->name}.");
                }
                foreach ($chosen as $other) {
                    if ($other->isGroup()) {
                        throw new TableUnavailableException("La mesa {$table->name} tiene mesas unidas: sepáralas antes de unirla.");
                    }
                    if ((int) $other->discount_value > 0 || $other->discount_amount > 0) {
                        throw new InvalidArgumentException("La cuenta de la mesa {$table->name} tiene un descuento global: quítalo antes de unirla (mesa {$table->name} → Cobrar → Descuento al pedido → Quitar descuento).");
                    }
                    if ($other->tickets()->exists()) {
                        throw new LogicException("La cuenta de la mesa {$table->name} ya tiene ticket.");
                    }
                }
                $plan[] = ['table' => $table, 'orders' => $chosen, 'joins' => $chosen->count() === $open->count()];
            }

            $joined = [];
            $merged = [];
            foreach ($plan as ['table' => $table, 'orders' => $chosen, 'joins' => $joins]) {
                foreach ($chosen as $other) {
                    $this->absorb($order, $other, $by);
                    $merged[] = $other;
                }
                if ($joins) {
                    $order->tableLinks()->create(['table_id' => $table->id, 'is_main' => false, 'joined_by' => $by->id, 'joined_at' => now()]);
                    $joined[] = $table;
                }
            }

            // Con cuentas unidas, la precuenta impresa ya no vale: el grupo vuelve a «ocupada».
            $status = $merged !== [] ? TableStatus::Occupied : $main->status;
            foreach ($this->lockGroupTables($order) as $table) {
                $table->update(['status' => $status]);
            }
            $this->totals->apply($order->refresh());

            if (Setting::current()->print_join_notice) {
                $this->printJoinNotice($order, array_merge($joined, array_map(fn (Order $o) => $tables->get($o->table_id), $merged)), $by);
            }

            return ['order' => $order->refresh(), 'joined' => $joined, 'merged' => $merged];
        });

        return $result;
    }

    /**
     * Separa mesas del grupo: vuelven a estar libres (si no tienen otra
     * cuenta). La principal no se puede separar. Las líneas se quedan en el
     * pedido; para llevar productos a otra mesa está la división por productos.
     *
     * @param  list<int>  $tableIds
     * @return list<Table>
     */
    public function separateTables(Order $order, array $tableIds, User $by): array
    {
        return DB::transaction(function () use ($order, $tableIds, $by) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($order);
            $links = $order->activeTableLinks()->get()->keyBy('table_id');
            $tables = Table::query()->whereIn('id', $links->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $separated = [];
            foreach (array_unique(array_map('intval', $tableIds)) as $id) {
                $link = $links->get($id) ?? throw new InvalidArgumentException('Esa mesa no está unida a este pedido.');
                if ($link->is_main) {
                    throw new InvalidArgumentException("La mesa {$tables[$id]->name} es la principal: no se puede separar.");
                }
                $link->update(['left_at' => now(), 'left_by' => $by->id]);
                $this->freeIfEmpty($tables[$id]);
                $separated[] = $tables[$id];
            }
            if ($separated === []) {
                throw new InvalidArgumentException('Elige las mesas que quieres separar.');
            }

            return $separated;
        });
    }

    /**
     * Por qué no se puede liberar el pedido como «abierto por error» (null =
     * se puede): ya se envió algo, se imprimió la cuenta o tiene ticket.
     */
    public function releaseBlocker(Order $order): ?string
    {
        return match (true) {
            ! $order->isOpen() => 'El pedido ya no está abierto.',
            ! in_array($order->type, [OrderType::Table, OrderType::Bar], true) => 'Solo se pueden liberar mesas y ventas rápidas.',
            $order->lines()->whereNotNull('sent_at')->exists() => 'Ya se envió a cocina: anula las líneas o cobra la mesa.',
            Precheck::query()->where('order_id', $order->id)->exists() => 'Ya se imprimió la cuenta: cobra la mesa o anula las líneas.',
            $order->tickets()->exists() => 'La mesa ya tiene ticket.',
            default => null,
        };
    }

    /**
     * Libera una mesa abierta por error: el pedido queda cancelado («Abierta
     * por error», quién y cuándo; no se borra, con sus líneas sin enviar) y
     * todas sus mesas vuelven a estar libres. Sin autorización de encargado.
     *
     * @return int productos sin enviar descartados
     */
    public function releaseOpenedByMistake(Order $order, User $by): int
    {
        return DB::transaction(function () use ($order, $by) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($reason = $this->releaseBlocker($order)) {
                throw new LogicException($reason);
            }
            $discarded = (int) $order->lines()->whereNull('parent_line_id')->whereNull('voided_at')->count();
            $tables = $this->lockGroupTables($order);
            $order->update([
                'status' => OrderStatus::Cancelled, 'closed_at' => now(),
                'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => 'Abierta por error',
            ]);
            $tables->each(fn (Table $t) => $this->freeIfEmpty($t));

            return $discarded;
        });
    }

    /** Las líneas de $other pasan a $order; $other queda «unido» (sin ticket). */
    private function absorb(Order $order, Order $other, User $by): void
    {
        // Los envíos de la otra mesa siguen a los de esta (no se reimprimen).
        $offset = (int) $order->send_count;
        OrderLine::query()->where('order_id', $other->id)->update([
            'order_id' => $order->id,
            'send_number' => DB::raw('CASE WHEN send_number IS NULL THEN NULL ELSE send_number + '.$offset.' END'),
        ]);
        $order->forceFill([
            'guests' => ($order->guests ?? 0) + ($other->guests ?? 0) ?: null,
            'send_count' => $offset + (int) $other->send_count,
        ])->save();
        $other->forceFill([
            'status' => OrderStatus::Merged, 'closed_at' => now(),
            'merged_into_order_id' => $order->id, 'merged_by' => $by->id, 'merged_at' => now(),
            'subtotal' => 0, 'total' => 0,
        ])->save();
    }

    /** «MESA 4 UNIDA A MESA 3» en cocina y barra (opcional; Ajustes). */
    private function printJoinNotice(Order $order, array $tables, User $by): void
    {
        $printers = Printer::query()->whereIn('type', [PrinterType::Kitchen, PrinterType::Bar])->where('is_active', true)->get();
        foreach ($tables as $table) {
            $printable = $this->formatter->joinNotice($order, $table, $by);
            foreach ($printers as $printer) {
                $this->printing->dispatch($order, $printer, PrintJobKind::JoinNotice, $printable, by: $by);
            }
        }
    }

    /**
     * Mesas del pedido (la principal y las unidas), bloqueadas en orden de id.
     *
     * @return Collection<int, Table>
     */
    private function lockGroupTables(Order $order)
    {
        $ids = $order->activeTableLinks()->pluck('table_id')->push($order->table_id)->filter()->unique();

        return Table::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    /** Bloquea la mesa y comprueba que está libre (si no, otro camarero se ha adelantado). */
    private function lockFreeTable(int $tableId): Table
    {
        $table = Table::query()->whereKey($tableId)->lockForUpdate()->firstOrFail();

        if (! $table->is_active || $table->status === TableStatus::OutOfService) {
            throw new TableUnavailableException("La mesa {$table->name} está fuera de servicio.");
        }
        if ($link = $table->groupLink()->with('order.table')->first()) {
            throw new TableUnavailableException("La mesa {$table->name} está unida a la mesa {$link->order->table->name}.");
        }
        if ($table->status !== TableStatus::Free || $table->openOrders()->exists()) {
            throw new TableUnavailableException("La mesa {$table->name} ya está abierta.");
        }

        return $table;
    }

    private function freeIfEmpty(Table $table): void
    {
        if (! $table->isInUse()) {
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
