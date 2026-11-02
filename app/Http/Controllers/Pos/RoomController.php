<?php

namespace App\Http\Controllers\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\Room;
use App\Models\Table;
use Illuminate\Http\JsonResponse;

/** Plano: salas con sus mesas y el resumen del pedido abierto de cada una. */
class RoomController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $rooms = Room::query()->where('is_active', true)->orderBy('sort_order')
            ->with(['tables' => fn ($q) => $q->where('is_active', true)->with([
                'openOrders' => fn ($o) => $o->with(['openedBy', 'lines' => fn ($l) => $l->whereNull('parent_line_id')->whereNull('voided_at')])
                    ->withExists('prechecks')->oldest('id'),
            ])])
            ->get();

        // Mesas unidas: cada mesa del grupo con el pedido, su etiqueta «3+4» y el total en la principal.
        $links = OrderTable::query()->whereNull('left_at')->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Open))
            ->with(['order.openedBy', 'order.lines' => fn ($l) => $l->whereNull('parent_line_id')->whereNull('voided_at'), 'diningTable'])
            ->orderByDesc('is_main')->orderBy('joined_at')->orderBy('id')->get()->groupBy('order_id')
            ->filter(fn ($group) => $group->count() > 1);
        self::$groups = [];
        foreach ($links as $orderId => $group) {
            $names = $group->map(fn ($l) => $l->diningTable->name);
            foreach ($group as $link) {
                self::$groups[$link->table_id] = [
                    'order_id' => $orderId,
                    'label' => $names->implode('+'),
                    'main_table_id' => $group->first()->table_id,
                    'is_main' => $link->is_main,
                    'table_ids' => $group->pluck('table_id')->all(),
                    'total' => $link->order->total,
                    'order' => self::order($link->order),
                ];
            }
        }

        return response()->json([
            'data' => $rooms->map(fn (Room $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'tables' => $room->tables->map(fn (Table $table) => self::table($table))->values(),
            ]),
            'unplaced' => self::unplaced($rooms),
        ]);
    }

    /** @var array<int, array<string, mixed>> mesa => grupo (mesas unidas) */
    private static array $groups = [];

    /**
     * Pedidos abiertos de sala que el plano no enseña: ventas rápidas de barra
     * sin cobrar, o mesas fuera de servicio o de una sala desactivada. Salen en
     * una franja encima del plano para que nunca se queden abiertos sin acceso
     * (y el Z no los arrastre para siempre).
     */
    private static function unplaced($rooms): array
    {
        $visible = $rooms->flatMap(fn (Room $room) => $room->tables->pluck('id'))->all();

        return Order::query()->where('status', OrderStatus::Open)->whereIn('type', [OrderType::Bar, OrderType::Table])
            ->where(fn ($q) => $q->whereNull('table_id')->orWhereNotIn('table_id', $visible ?: [0]))
            ->with(['openedBy', 'table.room', 'lines' => fn ($l) => $l->whereNull('parent_line_id')->whereNull('voided_at')])
            ->oldest('id')->get()
            ->map(fn (Order $o) => self::order($o) + [
                'type' => $o->type->value,
                'label' => $o->table ? "Mesa {$o->table->name}" : 'Venta rápida',
                'reason' => match (true) {
                    $o->table === null => 'sin mesa',
                    ! $o->table->room?->is_active => "sala {$o->table->room?->name} desactivada",
                    default => 'mesa fuera de servicio',
                },
            ])->values()->all();
    }

    public static function table(Table $table): array
    {
        // Una mesa puede tener varias cuentas abiertas (al dividir por productos).
        $orders = $table->openOrders->filter(fn ($o) => $o->status === OrderStatus::Open)->values();
        $group = self::$groups[$table->id] ?? null;
        $accounts = $orders->map(fn ($o) => self::order($o));
        if ($group && ! $group['is_main']) {
            $accounts = collect([$group['order']]);   // mesa unida: abre el pedido del grupo
        }

        return [
            'id' => $table->id,
            'room_id' => $table->room_id,
            'name' => $table->name,
            'seats' => $table->seats,
            'shape' => $table->shape->value,
            'x' => $table->pos_x, 'y' => $table->pos_y, 'w' => $table->width, 'h' => $table->height,
            'status' => $table->status->value,
            'total' => $group && ! $group['is_main'] ? 0 : (int) $orders->sum('total'),
            'order' => $accounts->first(), // la primera (compatibilidad)
            'orders' => $accounts->values()->all(),
            'group' => $group ? array_diff_key($group, ['order' => true]) : null,
        ];
    }

    private static function order($order): array
    {
        return [
            'id' => $order->id,
            'total' => $order->total,
            'guests' => $order->guests,
            'opened_at' => $order->opened_at->toIso8601String(),
            'notes' => $order->notes,
            'precheck_printed' => (bool) ($order->prechecks_exists ?? $order->prechecks()->exists()),
            // "3 Caña, 1 Bravas…" para elegir cuenta sin abrirla.
            'summary' => $order->lines->take(4)->map(fn ($l) => "{$l->quantity} {$l->name}")->implode(', ').($order->lines->count() > 4 ? '…' : ''),
            'waiter' => $order->openedBy ? [
                'id' => $order->openedBy->id, 'name' => $order->openedBy->name,
                'initials' => $order->openedBy->initials(), 'color' => $order->openedBy->color,
            ] : null,
        ];
    }
}
