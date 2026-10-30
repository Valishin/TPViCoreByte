<?php

namespace App\Http\Controllers\Pos;

use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Events\Pos\OrderUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\OrderResource;
use App\Http\Resources\Pos\StaffResource;
use App\Http\Resources\Pos\TakeawayOrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\DispatchService;
use App\Services\Orders\OrderCancellation;
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

    /** Pasos manuales: Listo y Entregado (para llevar ya cobrado, o ya pagado: se emite su ticket). */
    public function status(Request $request, Order $order, DispatchService $dispatch): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['listo', 'entregado'])],
            'invoice_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        if ($data['status'] === 'listo') {
            $this->takeaway->markReady($order);
        } elseif ($order->isOpen() && $order->prepaid_method) {
            $dispatch->handOverPrepaid($order, $request->user(), self::recipient($data));
        } else {
            $this->takeaway->markDelivered($order, $request->user());
        }

        return $this->respond($order, $request);
    }

    /** Domicilio: dar salida con un repartidor (emite el ticket e imprime el de domicilio). */
    public function dispatch(Request $request, Order $order, DispatchService $dispatch): JsonResponse
    {
        $data = $request->validate([
            'courier_id' => ['required', 'integer', 'exists:users,id'],
            'invoice_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);
        $ticket = $dispatch->dispatch($order, User::query()->findOrFail($data['courier_id']), $request->user(), self::recipient($data));

        return $this->respond($order, $request, 200, ['ticket' => ['id' => $ticket->id, 'number' => $ticket->fullNumber(), 'payment_status' => $ticket->payment_status->value]]);
    }

    /** Cancelar antes del ticket (con motivo; autorización si ya se envió a cocina). */
    public function cancel(Request $request, Order $order, OrderCancellation $cancellation): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'authorization_token' => ['nullable', 'string'],
        ]);
        $cancellation->cancel($order, $request->user(), $data['reason'], $data['authorization_token'] ?? null);

        return $this->respond($order, $request);
    }

    /** Repartidores activos (botones de "Dar salida"). */
    public function couriers(): JsonResponse
    {
        $couriers = User::query()->where('role', UserRole::Courier)->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => StaffResource::collection($couriers)]);
    }

    /** Repetir un pedido anterior del cliente en este pedido. */
    public function repeat(Request $request, Order $order, Order $source, OrderRepeater $repeater): JsonResponse
    {
        $skipped = $repeater->repeat($source, $order, $request->user());

        return $this->respond($order, $request, 200, ['skipped' => $skipped]);
    }

    /** Destinatario de la factura completa, si se pide (en lugar del ticket). */
    private static function recipient(array $data): ?Customer
    {
        return isset($data['invoice_customer_id']) ? Customer::query()->findOrFail($data['invoice_customer_id']) : null;
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
