<?php

namespace App\Services\Pos;

use App\Events\Pos\CatalogChanged;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Carta para el TPV con una versión (hash) para que el cliente la cachee y
 * solo la recargue cuando cambie.
 *
 * Cualquier cambio en la carta llama a touched(): borra la caché y programa UN
 * evento CatalogChanged al final de la petición (debounce: guardar un producto
 * con repeaters dispara decenas de eventos de modelo, pero un solo aviso).
 */
class CatalogService
{
    public const CACHE_KEY = 'pos.catalog';

    public const SCHEMA = 'v3';

    /** @return array{version: string, data: array} */
    public function get(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            // Solo arrays planos: la caché no deserializa objetos (colecciones).
            $data = json_decode(json_encode($this->build()), true);

            // El prefijo cambia si cambia la forma de los datos: invalida cachés de clientes antiguos.
            return ['version' => self::SCHEMA.'-'.substr(sha1(json_encode($data)), 0, 12), 'data' => $data];
        });
    }

    public function version(): string
    {
        return $this->get()['version'];
    }

    public static function touched(): void
    {
        Cache::forget(self::CACHE_KEY);

        // Marca en el contenedor (no estática): vale para esta petición/aplicación.
        if (app()->bound('pos.catalog.notify-scheduled')) {
            return;
        }
        app()->instance('pos.catalog.notify-scheduled', true);

        app()->terminating(function () {
            app()->forgetInstance('pos.catalog.notify-scheduled');
            event(new CatalogChanged(app(self::class)->version()));
        });
    }

    private function build(): array
    {
        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        $products = Product::query()
            ->where('is_active', true)
            ->whereIn('category_id', $categories->pluck('id'))
            ->with(['variants' => fn ($q) => $q->where('is_active', true), 'modifierGroups', 'menuGroups.items'])
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $groupIds = $products->flatMap->modifierGroups->pluck('id')->unique();
        $modifierGroups = ModifierGroup::query()->whereIn('id', $groupIds)
            ->with(['modifiers' => fn ($q) => $q->where('is_active', true)->with('variantPrices')])
            ->get();

        return [
            'categories' => $categories->map(fn (Category $c) => [
                'id' => $c->id, 'name' => $c->name, 'color' => $c->color,
                'quick_notes' => $c->quick_notes ?? [],
            ])->values(),
            // Notas rápidas generales (todas las categorías); en el TPV van detrás de las de la categoría.
            'quick_notes' => Setting::query()->first()?->quick_notes ?? [],
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'category_id' => $p->category_id,
                'name' => $p->name,
                'short_name' => $p->short_name,
                'type' => $p->type->value,
                'price' => $p->price,
                'available' => $p->is_available,
                'allows_half_and_half' => $p->allows_half_and_half,
                'allergens' => $p->allergens?->map->value->values() ?? [],
                'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'code' => $v->code, 'name' => $v->name, 'price' => $v->price])->values(),
                'modifier_group_ids' => $p->modifierGroups->pluck('id')->values(),
                'menu_groups' => $p->menuGroups->map(fn ($g) => [
                    'id' => $g->id, 'name' => $g->name, 'min' => $g->min_select, 'max' => $g->max_select,
                    'items' => $g->items->map(fn ($i) => [
                        'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->product_variant_id, 'supplement' => $i->supplement,
                    ])->values(),
                ])->values(),
            ])->values(),
            'modifier_groups' => $modifierGroups->map(fn (ModifierGroup $g) => [
                'id' => $g->id, 'name' => $g->name, 'min' => $g->min_select, 'max' => $g->max_select,
                'modifiers' => $g->modifiers->map(fn ($m) => [
                    'id' => $m->id, 'name' => $m->name, 'price_delta' => $m->price_delta,
                    'variant_prices' => (object) $m->variantPrices->pluck('price_delta', 'variant_code')->all(),
                ])->values(),
            ])->values(),
        ];
    }
}
