<?php

namespace App\Http\Controllers\Pos;

use App\Enums\DeliveryStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\TakeawayOrderResource;
use App\Models\Order;
use App\Services\Cash\CourierService;
use App\Support\MapsLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "Mis repartos" en el móvil del repartidor: solo los suyos, sin liquidar. */
class MyDeliveriesController extends Controller
{
    public function __construct(private CourierService $couriers) {}

    public function index(Request $request): JsonResponse
    {
        $orders = CourierService::unsettled($request->user())->with(['courier', 'deliveryZone', 'customer'])->get();

        return response()->json(['data' => $orders->map(fn (Order $o) => $this->present($o, $request))->values()]);
    }

    public function delivered(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'payments' => ['array'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', 'integer', 'min:0'],
        ]);
        $order = $this->couriers->reportDelivered($order, $request->user(), $data['payments'] ?? []);

        return response()->json(['data' => $this->present($order->fresh(), $request)]);
    }

    public function notDelivered(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->courier_id === $request->user()->id, 403, 'Este reparto no es tuyo.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:120'], 'authorization_token' => ['nullable', 'string']]);
        $this->couriers->failDelivery($order, $request->user(), $data['reason'], $data['authorization_token'] ?? null);

        return response()->json(['data' => $this->present($order->fresh(), $request)]);
    }

    private function present(Order $order, Request $request): array
    {
        return [
            ...(new TakeawayOrderResource($order))->resolve($request),
            'pending_amount' => CourierService::pendingAmount(CourierService::ticketOf($order)),
            'maps_url' => MapsLink::forAddress($order->delivery_address),
            'phone_url' => $order->customer_phone ? 'tel:'.$order->customer_phone : null,
            'reported_payments' => $order->courier_reported_payments,
            'delivered' => $order->delivery_status === DeliveryStatus::Delivered,
        ];
    }
}
