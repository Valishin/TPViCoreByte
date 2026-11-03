<?php

namespace App\Http\Controllers\Pos;

use App\Enums\OrderType;
use App\Events\Pos\OrderUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\OrderResource;
use App\Models\Category;
use App\Models\MenuGroupItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Table;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Pos\OrderSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Flujo de sala. Toda la lógica está en los servicios (OrderService,
 * OrderLineBuilder, OrderSender); aquí solo se valida la entrada, se llama
 * al servicio y se avisa a las pantallas.
 */
class OrderController extends Controller
{
    public function __construct(
        private OrderService $orders,
        private OrderLineBuilder $builder,
    ) {}

    public function openTable(Request $request, Table $table): JsonResponse
    {
        $data = $request->validate(['guests' => ['required', 'integer', 'min:1', 'max:99']]);

        $order = $this->orders->open(OrderType::Table, $request->user(), $table, $data['guests']);

        return $this->respond($order, $request, 201);
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['guests' => ['required', 'integer', 'min:1', 'max:99']]);
        $this->orders->setGuests($order, $data['guests']);

        return $this->respond($order, $request);
    }

    public function addLine(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer'],
            'modifier_ids' => ['array'],
            'modifier_ids.*' => ['integer'],
            'quantity' => ['integer', 'min:1', 'max:99'],
            'notes' => ['nullable', 'string', 'max:200'],
            'menu_choices' => ['array'],
            'menu_choices.*.item_id' => ['required', 'integer'],
            'menu_choices.*.modifier_ids' => ['array'],
            'half' => ['nullable', 'array'],
            'half.product_id' => ['required_with:half', 'integer', 'exists:products,id'],
            'half.modifier_ids' => ['array'],
        ]);

        $product = Product::query()->findOrFail($data['product_id']);
        $variant = isset($data['variant_id']) ? ProductVariant::query()->where('product_id', $product->id)->findOrFail($data['variant_id']) : null;
        $quantity = $data['quantity'] ?? 1;
        $notes = $data['notes'] ?? null;
        $by = $request->user();

        if (isset($data['half'])) {
            if (! $variant) {
                throw new InvalidArgumentException('Elige el tamaño de la pizza.');
            }
            $line = $this->builder->addHalfAndHalf(
                $order, $product, Product::query()->findOrFail($data['half']['product_id']), $variant->code,
                $this->modifiers($data['modifier_ids'] ?? []), $this->modifiers($data['half']['modifier_ids'] ?? []),
                $quantity, $notes, $by,
            );
        } elseif ($product->type->value === 'menu') {
            $items = MenuGroupItem::query()->whereIn('id', collect($data['menu_choices'] ?? [])->pluck('item_id'))->get()->keyBy('id');
            $choices = collect($data['menu_choices'] ?? [])->map(fn ($choice) => [
                'item' => $items->get($choice['item_id']) ?? throw new InvalidArgumentException('Elección de menú no válida.'),
                'modifiers' => $this->modifiers($choice['modifier_ids'] ?? []),
            ])->all();
            $line = $this->builder->addMenu($order, $product, $choices, $quantity, $notes, $by);
        } else {
            $line = $this->builder->addProduct($order, $product, $variant, $this->modifiers($data['modifier_ids'] ?? []), $quantity, $notes, $by);
        }

        return $this->respond($order, $request, 201, ['line_id' => $line->id]);
    }

    /** Producto personalizado (fuera de la carta): nombre y precio escritos en la caja. */
    public function addCustomLine(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'quantity' => ['integer', 'min:1', 'max:99'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);
        $line = $this->builder->addCustom($order, Category::query()->findOrFail($data['category_id']), $data['name'], $data['price'],
            $data['quantity'] ?? 1, $data['notes'] ?? null, $request->user());

        return $this->respond($order, $request, 201, ['line_id' => $line->id]);
    }

    public function updateLine(Request $request, OrderLine $line): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        $this->orders->updateLine($line, $data['quantity'] ?? null, $data['notes'] ?? null, $request->has('notes'));

        return $this->respond($line->order, $request);
    }

    public function voidLine(Request $request, OrderLine $line): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:120']]);

        $this->orders->voidLine($line, $request->user(), $data['reason'] ?? null);

        return $this->respond($line->order, $request);
    }

    public function send(Request $request, Order $order, OrderSender $sender): JsonResponse
    {
        $options = $request->validate([
            'accept_below_minimum' => ['boolean'],
            'authorization_token' => ['nullable', 'string'],
        ]);
        $result = $sender->send($order, $request->user(), $options);

        return $this->respond($order, $request, 200, ['sent' => $result]);
    }

    public function move(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['table_id' => ['required', 'integer', 'exists:tables,id']]);
        $this->orders->move($order, Table::query()->findOrFail($data['table_id']));

        return $this->respond($order, $request);
    }

    public function precheck(Request $request, Order $order): JsonResponse
    {
        $this->orders->precheck($order, $request->user());

        return $this->respond($order, $request);
    }

    /** Unir mesas: libres o con su(s) cuenta(s) (accounts: mesa => ids de pedido o «all»). */
    public function join(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'table_ids' => ['required', 'array', 'min:1', 'max:20'],
            'table_ids.*' => ['integer', 'exists:tables,id'],
            'accounts' => ['array'],
        ]);
        $result = $this->orders->joinTables($order, $data['table_ids'], $request->user(), $data['accounts'] ?? []);
        foreach ($result['merged'] as $merged) {
            event(new OrderUpdated($merged->fresh(), $request->user()->id));   // quien la tenga abierta, al grupo
        }

        return $this->respond($order, $request, 200, [
            'joined' => array_map(fn (Table $t) => $t->name, $result['joined']),
            'merged' => array_map(fn (Order $o) => $o->id, $result['merged']),
        ]);
    }

    public function separate(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['table_ids' => ['required', 'array', 'min:1'], 'table_ids.*' => ['integer']]);
        $tables = $this->orders->separateTables($order, $data['table_ids'], $request->user());

        return $this->respond($order, $request, 200, ['separated' => array_map(fn (Table $t) => $t->name, $tables)]);
    }

    /** Liberar una mesa abierta por error (sin nada enviado, sin cuenta impresa ni ticket). */
    public function release(Request $request, Order $order): JsonResponse
    {
        $discarded = $this->orders->releaseOpenedByMistake($order, $request->user());

        return $this->respond($order, $request, 200, ['discarded' => $discarded]);
    }

    public function changeType(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(OrderType::class)],
            'table_id' => ['nullable', 'integer', 'exists:tables,id'],
        ]);

        $this->orders->changeType($order, OrderType::from($data['type']), isset($data['table_id']) ? Table::query()->find($data['table_id']) : null);

        return $this->respond($order, $request);
    }

    /** @param  list<int>  $ids  (un id repetido = cantidad 2) */
    private function modifiers(array $ids): array
    {
        $models = Modifier::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $models->get($id) ?? throw new InvalidArgumentException('Modificador no válido.'))->all();
    }

    private function respond(Order $order, Request $request, int $status = 200, array $extra = []): JsonResponse
    {
        $order = $order->fresh();
        event(new OrderUpdated($order, $request->user()?->id));

        return response()->json(['data' => (new OrderResource($order))->resolve($request)] + $extra, $status);
    }
}
