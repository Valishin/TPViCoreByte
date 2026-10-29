<?php

namespace App\Http\Controllers\Pos;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
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
                'openOrders' => fn ($o) => $o->with(['openedBy', 'lines' => fn ($l) => $l->whereNull('parent_line_id')->whereNull('voided_at')])->oldest('id'),
            ])])
            ->get();

        return response()->json(['data' => $rooms->map(fn (Room $room) => [
            'id' => $room->id,
            'name' => $room->name,
            'tables' => $room->tables->map(fn (Table $table) => self::table($table))->values(),
        ])]);
    }

    public static function table(Table $table): array
    {
        // Una mesa puede tener varias cuentas abiertas (al dividir por productos).
        $orders = $table->openOrders->filter(fn ($o) => $o->status === OrderStatus::Open)->values();

        return [
            'id' => $table->id,
            'room_id' => $table->room_id,
            'name' => $table->name,
            'seats' => $table->seats,
            'shape' => $table->shape->value,
            'x' => $table->pos_x, 'y' => $table->pos_y, 'w' => $table->width, 'h' => $table->height,
            'status' => $table->status->value,
            'total' => (int) $orders->sum('total'),
            'order' => $orders->isNotEmpty() ? self::order($orders->first()) : null, // la primera (compatibilidad)
            'orders' => $orders->map(fn ($o) => self::order($o))->all(),
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
            // "3 Caña, 1 Bravas…" para elegir cuenta sin abrirla.
            'summary' => $order->lines->take(4)->map(fn ($l) => "{$l->quantity} {$l->name}")->implode(', ').($order->lines->count() > 4 ? '…' : ''),
            'waiter' => $order->openedBy ? [
                'id' => $order->openedBy->id, 'name' => $order->openedBy->name,
                'initials' => $order->openedBy->initials(), 'color' => $order->openedBy->color,
            ] : null,
        ];
    }
}
