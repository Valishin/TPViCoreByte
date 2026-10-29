<?php

namespace App\Http\Controllers\Pos;

use App\Enums\OrderType;
use App\Events\Pos\OrderUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\OrderResource;
use App\Http\Resources\Pos\TakeawayOrderResource;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Orders\OrderRepeater;
use App\Services\Orders\TakeawayOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pedidos para llevar y a domicilio: tablero, alta, datos y estados. */
class TakeawayOrderController extends Controller
{
    public function __construct(private TakeawayOrderService $takeaway) {}

    public function index(Request $request): JsonResponse
    {
        $orders = $this->takeaway->board()->with('lines')->get();
        $settings = Setting::current();

        return response()->json([
            'data' => TakeawayOrderResource::collection($orders)->resolve($request),
            'settings' => [
                'takeaway_prep_minutes' => $settings->takeaway_prep_minutes,
                'delivery_minutes' => $settings->delivery_minutes,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([OrderType::Takeaway->value, OrderType::Delivery->value])],
            ...$this->rules(),
        ]);

        $order = $this->takeaway->create(OrderType::from($data['type']), $request->user(), $data);

        return $this->respond($order, $request, 201);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $order = $this->takeaway->update($order, $request->validate($this->rules()));

        return $this->respond($order, $request);
    }

    /** Pasos manuales: Listo y Entregado (para llevar ya cobrado). */
    public function status(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['listo', 'entregado'])]]);

        $order = $data['status'] === 'listo'
            ? $this->takeaway->markReady($order)
            : $this->takeaway->markDelivered($order, $request->user());

        return $this->respond($order, $request);
    }

    /** Repetir un pedido anterior del cliente en este pedido. */
    public function repeat(Request $request, Order $order, Order $source, OrderRepeater $repeater): JsonResponse
    {
        $skipped = $repeater->repeat($source, $order, $request->user());

        return $this->respond($order, $request, 200, ['skipped' => $skipped]);
    }

    private function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'address_id' => ['nullable', 'integer', 'exists:customer_addresses,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'asap' => ['boolean'],
            'promised_in' => ['nullable', 'integer', 'min:0', 'max:600'],
            'promised_at' => ['nullable', 'date'],
            'payment' => ['nullable', 'string', 'max:30'],
            'cash_change_for' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function respond(Order $order, Request $request, int $status = 200, array $extra = []): JsonResponse
    {
        $order = $order->fresh();
        event(new OrderUpdated($order, $request->user()?->id));

        return response()->json([
            'data' => (new OrderResource($order))->resolve($request),
            'takeaway' => (new TakeawayOrderResource($order))->resolve($request),
        ] + $extra, $status);
    }
}
