<?php

namespace App\Services\Orders;

use App\Enums\OrderLineKind;
use App\Enums\ProductType;
use App\Models\MenuGroupItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * "Repetir último pedido": vuelve a añadir las líneas de un pedido anterior
 * con la carta de HOY (precios, IVA y disponibilidad actuales), no con los
 * importes antiguos. Lo que ya no se vende se salta y se avisa.
 */
class OrderRepeater
{
    public function __construct(private OrderLineBuilder $builder) {}

    /** @return list<string> nombres de las líneas que no se han podido repetir */
    public function repeat(Order $source, Order $target, User $by): array
    {
        if ($source->customer_id === null || $source->customer_id !== $target->customer_id) {
            throw new InvalidArgumentException('Solo se repite un pedido del mismo cliente.');
        }
        if (! $target->isOpen()) {
            throw new LogicException('El pedido no está abierto.');
        }

        $lines = $source->lines()->whereNull('parent_line_id')->whereNull('voided_at')
            ->with(['product.variants', 'modifiers.modifier', 'children.product.variants', 'children.modifiers.modifier'])
            ->orderBy('sort_order')->get();

        $skipped = [];
        DB::transaction(function () use ($lines, $target, $by, &$skipped) {
            foreach ($lines as $line) {
                try {
                    $this->repeatLine($line, $target, $by);
                } catch (InvalidArgumentException|LogicException) {
                    $skipped[] = $line->name;
                }
            }
        });

        return $skipped;
    }

    private function repeatLine(OrderLine $line, Order $target, User $by): void
    {
        $children = $line->children;

        // Mitad y mitad
        if ($children->isNotEmpty() && $children->every(fn ($c) => $c->kind === OrderLineKind::Half)) {
            [$a, $b] = $children->sortBy('sort_order')->values()->all();
            if (! $a->product || ! $b->product) {
                throw new InvalidArgumentException('Producto retirado');
            }
            $this->builder->addHalfAndHalf($target, $a->product, $b->product, (string) $line->variant_code,
                $this->modifiers($a), $this->modifiers($b), $line->quantity, $line->notes, $by);

            return;
        }

        $product = $line->product ?? throw new InvalidArgumentException('Producto retirado');

        // Menú: cada componente, por su producto y variante, en los grupos actuales.
        if ($product->type === ProductType::Menu) {
            $product->load('menuGroups.items');
            $items = $product->menuGroups->flatMap->items;
            $choices = $children->sortBy('sort_order')->map(function (OrderLine $child) use ($items) {
                $item = $items->first(fn (MenuGroupItem $i) => $i->product_id === $child->product_id
                    && $i->product_variant_id === $child->product_variant_id)
                    ?? throw new InvalidArgumentException('Componente retirado');

                return ['item' => $item, 'modifiers' => $this->modifiers($child)];
            })->values()->all();
            $this->builder->addMenu($target, $product, $choices, $line->quantity, $line->notes, $by);

            return;
        }

        $variant = $line->product_variant_id ? $product->variants->firstWhere('id', $line->product_variant_id) : null;
        if ($line->product_variant_id && ! $variant) {
            throw new InvalidArgumentException('Tamaño retirado');
        }
        $this->builder->addProduct($target, $product, $variant, $this->modifiers($line), $line->quantity, $line->notes, $by);
    }

    /** @return list<Modifier> (repetidos según su cantidad) */
    private function modifiers(OrderLine $line): array
    {
        $list = [];
        foreach ($line->modifiers as $m) {
            if (! $m->modifier || ! $m->modifier->is_active) {
                throw new InvalidArgumentException('Modificador retirado');
            }
            array_push($list, ...array_fill(0, max(1, (int) $m->quantity), $m->modifier));
        }

        return $list;
    }
}
