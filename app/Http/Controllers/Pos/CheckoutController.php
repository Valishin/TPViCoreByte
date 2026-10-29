<?php

namespace App\Http\Controllers\Pos;

use App\Enums\AuthorizationAction;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Events\Pos\OrderUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\OrderResource;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Setting;
use App\Services\Orders\BillSplitter;
use App\Services\Orders\OrderService;
use App\Services\Pos\AuthorizationService;
use App\Services\Pos\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Cobro, descuentos/invitaciones, venta rápida y división por productos. */
class CheckoutController extends Controller
{
    public function __construct(
        private OrderService $orders,
        private AuthorizationService $authorization,
    ) {}

    /** Venta rápida de barra (sin mesa). */
    public function quickSale(Request $request): JsonResponse
    {
        $order = $this->orders->open(OrderType::Bar, $request->user());

        return response()->json(['data' => (new OrderResource($order))->resolve($request)], 201);
    }

    public function checkout(Request $request, Order $order, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
            'payments' => ['present', 'array'],
            'payments.*.method' => ['required', 'string'],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.tendered' => ['nullable', 'integer', 'min:0'],
            'payments.*.tip' => ['nullable', 'integer', 'min:0'],
            'print' => ['nullable', 'boolean'],
            'payment_receipts' => ['boolean'],
        ]);

        $result = $checkout->checkout($order, $request->user(), $data['payments'], $data['idempotency_key'], $data['print'] ?? null, $data['payment_receipts'] ?? false);
        if (! $result['replayed']) {
            event(new OrderUpdated($order->fresh(), $request->user()->id));
        }

        return response()->json(['data' => [
            'ticket' => TicketController::summary($result['ticket']->load(['order.table', 'issuedBy', 'payments', 'rectifiedTicket'])),
            'change' => $result['change'],
            'replayed' => $result['replayed'],
        ]], $result['replayed'] ? 200 : 201);
    }

    public function discountLine(Request $request, OrderLine $line): JsonResponse
    {
        $data = $this->validateDiscount($request);
        $base = $line->line_total;
        $this->authorizeDiscount($request, DiscountType::from($data['type']), $data['value'], $base, $line, $data);
        $this->orders->discountLine($line, DiscountType::from($data['type']), $data['value'], $request->user(), $data['reason'] ?? null);

        return $this->respond($line->order, $request);
    }

    public function inviteLine(Request $request, OrderLine $line): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:120'], 'authorization_token' => ['nullable', 'string']]);
        $this->authorization->require(AuthorizationAction::Invitation, $request->user(), $data['authorization_token'] ?? null, $line, $data['reason'] ?? null);
        $this->orders->inviteLine($line, $request->user(), $data['reason'] ?? null);

        return $this->respond($line->order, $request);
    }

    public function discountOrder(Request $request, Order $order): JsonResponse
    {
        $data = $this->validateDiscount($request);
        $this->authorizeDiscount($request, DiscountType::from($data['type']), $data['value'], $order->subtotal, $order, $data);
        $this->orders->discountOrder($order, DiscountType::from($data['type']), $data['value'], $request->user(), $data['reason'] ?? null);

        return $this->respond($order, $request);
    }

    /** Dividir por productos: las unidades elegidas pasan a un pedido nuevo que se cobra aparte. */
    public function split(Request $request, Order $order, BillSplitter $splitter): JsonResponse
    {
        $data = $request->validate(['lines' => ['required', 'array', 'min:1'], 'lines.*' => ['integer', 'min:0']]);
        $new = $splitter->splitByProducts($order, array_map('intval', $data['lines']), $request->user());
        event(new OrderUpdated($order->fresh(), $request->user()->id));

        return response()->json(['data' => (new OrderResource($new->fresh()))->resolve($request)], 201);
    }

    private function validateDiscount(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::enum(DiscountType::class)],
            'value' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:120'],
            'authorization_token' => ['nullable', 'string'],
        ]);
    }

    /** Por encima del límite (Ajustes → % sin autorización) hace falta un encargado. */
    private function authorizeDiscount(Request $request, DiscountType $type, int $value, int $base, $subject, array $data): void
    {
        $bp = $type === DiscountType::Percentage ? $value : ($base > 0 ? intdiv($value * 10000 + $base - 1, $base) : 10000);
        if ($bp > Setting::current()->discount_limit_bp) {
            $this->authorization->require(AuthorizationAction::Discount, $request->user(), $data['authorization_token'] ?? null, $subject, $data['reason'] ?? null, ['type' => $type->value, 'value' => $value]);
        }
    }

    private function respond(Order $order, Request $request): JsonResponse
    {
        $order = $order->fresh();
        event(new OrderUpdated($order, $request->user()->id));

        return response()->json(['data' => (new OrderResource($order))->resolve($request)]);
    }
}
