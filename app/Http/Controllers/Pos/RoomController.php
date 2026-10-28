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
                'openOrders' => fn ($o) => $o->with('openedBy')->latest('id'),
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
        $order = $table->openOrders->first();

        return [
            'id' => $table->id,
            'room_id' => $table->room_id,
            'name' => $table->name,
            'seats' => $table->seats,
            'shape' => $table->shape->value,
            'x' => $table->pos_x, 'y' => $table->pos_y, 'w' => $table->width, 'h' => $table->height,
            'status' => $table->status->value,
            'order' => $order && $order->status === OrderStatus::Open ? [
                'id' => $order->id,
                'total' => $order->total,
                'guests' => $order->guests,
                'opened_at' => $order->opened_at->toIso8601String(),
                'waiter' => $order->openedBy ? [
                    'id' => $order->openedBy->id, 'name' => $order->openedBy->name,
                    'initials' => $order->openedBy->initials(), 'color' => $order->openedBy->color,
                ] : null,
            ] : null,
        ];
    }
}
