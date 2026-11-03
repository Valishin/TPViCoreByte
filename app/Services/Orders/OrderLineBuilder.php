<?php

namespace App\Services\Orders;

use App\Enums\OrderLineKind;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\MenuGroupItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Añade líneas a un pedido copiando nombre, precio e IVA del producto
 * (snapshot). El IVA aplicado depende del tipo de pedido (vat_rate_takeaway
 * en para llevar / domicilio). Tras cada alta recalcula los totales.
 */
class OrderLineBuilder
{
    public function __construct(private OrderTotalsCalculator $totals) {}

    /**
     * Producto simple o con variante.
     *
     * @param  list<Modifier>  $modifiers  (repetir un modificador = cantidad 2)
     */
    public function addProduct(
        Order $order,
        Product $product,
        ?ProductVariant $variant = null,
        array $modifiers = [],
        int $quantity = 1,
        ?string $notes = null,
        ?User $by = null,
        ?int $course = null,
    ): OrderLine {
        $this->assertEditable($order);
        $this->assertSellable($product);
        if ($product->type === ProductType::Menu) {
            throw new InvalidArgumentException('Los menús se añaden con addMenu().');
        }
        $this->assertVariant($product, $variant);
        $this->assertModifiers($product, $modifiers);

        $notes = blank($notes) ? null : trim($notes);

        return DB::transaction(function () use ($order, $product, $variant, $modifiers, $quantity, $notes, $by, $course) {
            // Línea idéntica pendiente de enviar → se suma la cantidad.
            if ($same = $this->findIdenticalPendingLine($order, $product, $variant, $modifiers, $notes, $course)) {
                $same->increment('quantity', $quantity);
                $this->totals->apply($order->refresh());

                return $same->refresh();
            }

            $line = $order->lines()->create($this->snapshot($order, $product, $variant) + [
                'kind' => OrderLineKind::Normal,
                'unit_price' => $product->priceFor($variant),
                'quantity' => $quantity,
                'notes' => $notes,
                'course' => $course,
                'created_by' => $by?->id,
                'sort_order' => $this->nextSortOrder($order),
            ]);
            $this->attachModifiers($line, $modifiers, $variant?->code);

            $this->totals->apply($order->refresh());

            return $line->refresh();
        });
    }

    /**
     * Producto personalizado (no está en la carta): nombre y precio escritos en la
     * caja («Varios», «Tarta de cumpleaños»…). Sin producto: va a la impresora de su
     * categoría y con el IVA más habitual de sus productos (10 % si no tiene).
     */
    public function addCustom(Order $order, Category $category, string $name, int $unitPrice, int $quantity = 1, ?string $notes = null, ?User $by = null): OrderLine
    {
        $this->assertEditable($order);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Escribe el nombre del producto.');
        }
        if ($unitPrice < 0) {
            throw new InvalidArgumentException('El precio no puede ser negativo.');
        }

        return DB::transaction(function () use ($order, $category, $name, $unitPrice, $quantity, $notes, $by) {
            $line = $order->lines()->create([
                'kind' => OrderLineKind::Normal,
                'product_id' => null,
                'printer_id' => $category->printer_id,
                'name' => $name,
                'vat_rate' => self::categoryVatRate($category, $order),
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'notes' => blank($notes) ? null : trim($notes),
                'created_by' => $by?->id,
                'sort_order' => $this->nextSortOrder($order),
            ]);
            $this->totals->apply($order->refresh());

            return $line->refresh();
        });
    }

    /** IVA más habitual de los productos activos de la categoría para este tipo de pedido (10 % si no hay). */
    public static function categoryVatRate(Category $category, Order $order): int
    {
        return Product::query()->where('category_id', $category->id)->where('is_active', true)->get()
            ->map(fn (Product $p) => $p->vatRateFor($order->type))
            ->countBy()->sortDesc()->keys()->first() ?? 1000;
    }

    /**
     * Menú / combo.
     *
     * @param  list<array{item: MenuGroupItem, modifiers?: list<Modifier>}>  $choices
     */
    public function addMenu(Order $order, Product $menu, array $choices, int $quantity = 1, ?string $notes = null, ?User $by = null): OrderLine
    {
        $this->assertEditable($order);
        $this->assertSellable($menu);
        if ($menu->type !== ProductType::Menu) {
            throw new InvalidArgumentException("{$menu->name} no es un menú.");
        }

        $menu->loadMissing('menuGroups.items');
        $this->assertMenuChoices($menu, $choices);

        return DB::transaction(function () use ($order, $menu, $choices, $quantity, $notes, $by) {
            $parent = $order->lines()->create($this->snapshot($order, $menu, null) + [
                'kind' => OrderLineKind::Normal,
                'unit_price' => $menu->price,
                'quantity' => $quantity,
                'notes' => $notes,
                'created_by' => $by?->id,
                'printer_id' => null, // cada componente va a su impresora
                'sort_order' => $this->nextSortOrder($order),
            ]);

            foreach (array_values($choices) as $i => $choice) {
                $item = $choice['item']->loadMissing('product.category', 'variant');
                $modifiers = $choice['modifiers'] ?? [];
                $this->assertSellable($item->product);
                $this->assertModifiers($item->product, $modifiers);

                $child = $order->lines()->create($this->snapshot($order, $item->product, $item->variant) + [
                    'parent_line_id' => $parent->id,
                    'kind' => OrderLineKind::MenuComponent,
                    'unit_price' => $item->supplement ?? 0,
                    'reference_price' => $item->product->priceFor($item->variant),
                    'quantity' => 1,
                    'created_by' => $by?->id,
                    'sort_order' => $i,
                ]);
                $this->attachModifiers($child, $modifiers, $item->variant?->code);
            }

            $this->totals->apply($order->refresh());

            return $parent->refresh();
        });
    }

    /**
     * Pizza mitad y mitad: dos productos que lo admiten, en el mismo tamaño.
     *
     * @param  list<Modifier>  $modifiersA
     * @param  list<Modifier>  $modifiersB
     */
    public function addHalfAndHalf(
        Order $order,
        Product $first,
        Product $second,
        string $variantCode,
        array $modifiersA = [],
        array $modifiersB = [],
        int $quantity = 1,
        ?string $notes = null,
        ?User $by = null,
    ): OrderLine {
        $this->assertEditable($order);

        $halves = [];
        foreach ([[$first, $modifiersA], [$second, $modifiersB]] as [$product, $modifiers]) {
            $this->assertSellable($product);
            if (! $product->allows_half_and_half) {
                throw new InvalidArgumentException("{$product->name} no admite mitad y mitad.");
            }
            $variant = $product->variants()->where('code', $variantCode)->first();
            if (! $variant) {
                throw new InvalidArgumentException("{$product->name} no tiene el tamaño {$variantCode}.");
            }
            $this->assertModifiers($product, $modifiers);
            $halves[] = [$product, $variant, $modifiers];
        }

        return DB::transaction(function () use ($order, $halves, $variantCode, $quantity, $notes, $by, $first, $second) {
            [, $variant] = $halves[0];

            $parent = $order->lines()->create([
                'kind' => OrderLineKind::Normal,
                'product_id' => null,
                'product_variant_id' => null,
                'printer_id' => null,
                'name' => "Mitad y mitad: {$first->name} / {$second->name}",
                'variant_name' => $variant->name,
                'variant_code' => $variantCode,
                'unit_price' => 0, // lo fija OrderTotalsCalculator según la regla
                // Suplemento por mitad y mitad: el mayor de los dos productos.
                'half_and_half_surcharge' => max((int) $first->half_and_half_surcharge, (int) $second->half_and_half_surcharge),
                'vat_rate' => $first->vatRateFor($order->type),
                'quantity' => $quantity,
                'notes' => $notes,
                'created_by' => $by?->id,
                'sort_order' => $this->nextSortOrder($order),
            ]);

            foreach ($halves as $i => [$product, $halfVariant, $modifiers]) {
                $child = $order->lines()->create($this->snapshot($order, $product, $halfVariant) + [
                    'parent_line_id' => $parent->id,
                    'kind' => OrderLineKind::Half,
                    'unit_price' => 0,
                    'reference_price' => $halfVariant->price,
                    'quantity' => 1,
                    'created_by' => $by?->id,
                    'sort_order' => $i,
                ]);
                $this->attachModifiers($child, $modifiers, $variantCode);
            }

            $this->totals->apply($order->refresh());

            return $parent->refresh();
        });
    }

    /**
     * Línea pendiente idéntica: mismo producto, variante, modificadores y notas,
     * sin hijas, sin descuento ni invitación, sin enviar ni anular.
     */
    private function findIdenticalPendingLine(Order $order, Product $product, ?ProductVariant $variant, array $modifiers, ?string $notes, ?int $course): ?OrderLine
    {
        $signature = fn ($items) => collect($items)->countBy(fn ($m) => $m->modifier_id ?? $m->id)->sortKeys()->all();
        $wanted = $signature($modifiers);

        return $order->lines()
            ->whereNull('parent_line_id')->whereNull('sent_at')->whereNull('voided_at')
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->where('notes', $notes)
            ->where('course', $course)
            ->whereNull('discount_type')->where('is_invitation', false)
            ->whereDoesntHave('children')
            ->with('modifiers')
            ->get()
            ->first(fn (OrderLine $line) => $line->modifiers->mapWithKeys(fn ($m) => [$m->modifier_id => $m->quantity])->sortKeys()->all() === $wanted);
    }

    /** Campos copiados del producto. */
    private function snapshot(Order $order, Product $product, ?ProductVariant $variant): array
    {
        $product->loadMissing('category');

        return [
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'printer_id' => $product->resolvePrinterId(),
            'name' => $product->name,
            'variant_name' => $variant?->name,
            'variant_code' => $variant?->code,
            'vat_rate' => $product->vatRateFor($order->type),
        ];
    }

    /** @param  list<Modifier>  $modifiers */
    private function attachModifiers(OrderLine $line, array $modifiers, ?string $variantCode): void
    {
        foreach (collect($modifiers)->groupBy('id') as $same) {
            /** @var Modifier $modifier */
            $modifier = $same->first()->loadMissing('variantPrices');
            $line->modifiers()->create([
                'modifier_id' => $modifier->id,
                'name' => $modifier->name,
                'price_delta' => $modifier->priceDeltaFor($variantCode),
                'quantity' => $same->count(),
            ]);
        }
    }

    private function assertEditable(Order $order): void
    {
        if (! $order->isOpen()) {
            throw new LogicException('El pedido no está abierto.');
        }
    }

    private function assertSellable(Product $product): void
    {
        if (! $product->is_active || ! $product->is_available) {
            throw new InvalidArgumentException("{$product->name} no está disponible.");
        }
    }

    private function assertVariant(Product $product, ?ProductVariant $variant): void
    {
        if ($product->type === ProductType::Variants && $variant === null) {
            throw new InvalidArgumentException("{$product->name} requiere elegir una variante.");
        }
        if ($variant !== null && $variant->product_id !== $product->id) {
            throw new InvalidArgumentException('La variante no pertenece al producto.');
        }
    }

    /** Comprueba que los modificadores son de grupos del producto y respetan mínimos y máximos. */
    private function assertModifiers(Product $product, array $modifiers): void
    {
        $groups = $product->modifierGroups()->get()->keyBy('id');
        $chosen = collect($modifiers)->groupBy('modifier_group_id');

        foreach ($chosen as $groupId => $items) {
            $group = $groups->get($groupId);
            if (! $group) {
                throw new InvalidArgumentException("El modificador {$items->first()->name} no se puede aplicar a {$product->name}.");
            }
            if ($group->max_select !== null && $items->count() > $group->max_select) {
                throw new InvalidArgumentException("{$group->name}: máximo {$group->max_select}.");
            }
        }

        foreach ($groups as $group) {
            if ($group->isRequired() && $chosen->get($group->id, collect())->count() < $group->min_select) {
                throw new InvalidArgumentException("{$product->name}: elige {$group->name}.");
            }
        }
    }

    private function assertMenuChoices(Product $menu, array $choices): void
    {
        $byGroup = collect($choices)->groupBy(fn ($choice) => $choice['item']->menu_group_id);

        foreach ($byGroup->keys() as $groupId) {
            if (! $menu->menuGroups->contains('id', $groupId)) {
                throw new InvalidArgumentException("La elección no pertenece al menú {$menu->name}.");
            }
        }

        foreach ($menu->menuGroups as $group) {
            $count = $byGroup->get($group->id, collect())->count();
            if ($count < $group->min_select || $count > $group->max_select) {
                throw new InvalidArgumentException("{$menu->name}: {$group->name} requiere entre {$group->min_select} y {$group->max_select}.");
            }
        }
    }

    private function nextSortOrder(Order $order): int
    {
        return (int) $order->lines()->whereNull('parent_line_id')->max('sort_order') + 1;
    }
}
