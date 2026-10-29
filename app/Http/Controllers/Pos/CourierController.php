<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\TakeawayOrderResource;
use App\Models\CourierSettlement;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CourierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pantalla "Repartidores" de la caja: fondo de cambio, liquidación y no entregados. */
class CourierController extends Controller
{
    public function __construct(private CourierService $couriers) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => collect($this->couriers->overview())->map(fn (array $row) => [
            ...$row,
            'orders' => $row['orders']->map(fn (Order $o) => [
                ...(new TakeawayOrderResource($o))->resolve($request),
                'pending_amount' => CourierService::pendingAmount(CourierService::ticketOf($o)),
            ])->values(),
        ])->values()]);
    }

    public function giveFloat(Request $request, User $courier): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:100000']]);
        $movement = $this->couriers->giveFloat($courier, $data['amount'], $request->user());

        return response()->json(['float' => CourierService::outstandingFloat($courier), 'movement_id' => $movement->id], 201);
    }

    public function settle(Request $request, User $courier): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer'],
            'collected' => ['array'],
            'collected.*' => ['array'],
            'collected.*.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'collected.*.*.amount' => ['required', 'integer', 'min:1'],
            'handed_cash' => ['nullable', 'integer', 'min:0'],
        ]);

        $settlement = $this->couriers->settle($courier, $request->user(), $data['collected'] ?? [], $data['handed_cash'] ?? null, $data['order_ids'] ?? null);

        return response()->json(['settlement' => self::settlement($settlement)], 201);
    }

    /** No entregado: rectificativo total sin devolución (con autorización). */
    public function notDelivered(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:120'], 'authorization_token' => ['nullable', 'string']]);
        $rectifying = $this->couriers->failDelivery($order, $request->user(), $data['reason'], $data['authorization_token'] ?? null);

        return response()->json([
            'takeaway' => (new TakeawayOrderResource($order->fresh()))->resolve($request),
            'rectifying' => ['id' => $rectifying->id, 'number' => $rectifying->fullNumber(), 'total' => $rectifying->total],
        ]);
    }

    public static function settlement(CourierSettlement $s): array
    {
        return [
            'id' => $s->id, 'courier_id' => $s->courier_id, 'float' => $s->float_amount,
            'cash' => $s->cash_collected, 'card' => $s->card_collected, 'other' => $s->other_collected,
            'expected_cash' => $s->expected_cash, 'handed_cash' => $s->handed_cash, 'difference' => $s->difference,
            'orders' => $s->orders()->pluck('id'),
        ];
    }
}
