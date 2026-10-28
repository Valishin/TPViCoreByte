<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Duplica un producto con sus variantes, grupos de modificadores y grupos del
 * menú. La copia se llama "… (copia)" y se crea NO activa, para revisarla
 * antes de que aparezca en el TPV.
 */
class ProductDuplicator
{
    public function duplicate(Product $product): Product
    {
        $product->loadMissing(['variants', 'modifierGroups', 'menuGroups.items']);

        return DB::transaction(function () use ($product) {
            $copy = $product->replicate(['deleted_at']);
            $copy->name = $product->name.' (copia)';
            $copy->is_active = false;
            $copy->sort_order = $product->sort_order + 1;
            $copy->save();

            foreach ($product->variants as $variant) {
                $copy->variants()->create($variant->replicate(['product_id'])->toArray());
            }

            $copy->modifierGroups()->sync(
                $product->modifierGroups->mapWithKeys(fn ($group) => [$group->id => ['sort_order' => $group->pivot->sort_order]])->all()
            );

            foreach ($product->menuGroups as $group) {
                $newGroup = $copy->menuGroups()->create($group->replicate(['product_id'])->toArray());
                foreach ($group->items as $item) {
                    $newGroup->items()->create($item->replicate(['menu_group_id'])->toArray());
                }
            }

            return $copy;
        });
    }
}
