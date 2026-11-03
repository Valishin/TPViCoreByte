<?php

namespace Tests\Feature\Filament;

use App\Enums\ProductType;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\VariantGroups\Pages\CreateVariantGroup;
use App\Models\Category;
use App\Models\Product;
use App\Models\VariantGroup;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * Grupos de variantes (Carta → Grupos de variantes): se crean una vez y, al
 * elegirlos en un producto, sus variantes se copian (con precio o para ponerlo
 * a mano) sin repetir los códigos de tamaño.
 */
class VariantGroupTest extends FilamentTestCase
{
    private function sizes(): VariantGroup
    {
        $group = VariantGroup::create(['name' => 'Tamaños de pizza']);
        $group->items()->createMany([
            ['name' => 'Individual', 'code' => 'individual', 'price' => 800, 'sort_order' => 1],
            ['name' => 'Mediana', 'code' => 'mediana', 'price' => null, 'sort_order' => 2],
            ['name' => 'Familiar', 'code' => 'familiar', 'price' => 1600, 'sort_order' => 3],
        ]);

        return $group;
    }

    public function test_create_a_variant_group_with_optional_prices(): void
    {
        $undo = Repeater::fake();
        Livewire::test(CreateVariantGroup::class)
            ->fillForm(['name' => 'Tamaños de pizza', 'items' => [
                ['name' => 'Mediana', 'code' => 'mediana', 'price' => ''],
                ['name' => 'Familiar', 'code' => 'familiar', 'price' => '16,00'],
            ]])
            ->call('create')
            ->assertHasNoFormErrors();
        $undo();

        $group = VariantGroup::query()->with('items')->sole();
        $this->assertSame([['mediana', null], ['familiar', 1600]], $group->items->map(fn ($i) => [$i->code, $i->price])->all());
    }

    public function test_choosing_a_group_copies_its_variants_without_repeating_codes(): void
    {
        $group = $this->sizes();
        $variants = ProductForm::withGroupVariants(['x' => ['name' => 'Mediana XL', 'code' => 'MEDIANA', 'price' => '11,00', 'is_active' => true]], $group->id);

        $this->assertSame(['Mediana XL', 'Individual', 'Familiar'], array_column(array_values($variants), 'name'), 'La mediana ya estaba: no se repite.');
        $this->assertSame(['11,00', '8,00', '16,00'], array_column(array_values($variants), 'price'));
        $this->assertNull(ProductForm::withGroupVariants([], $group->id)[array_keys(ProductForm::withGroupVariants([], $group->id))[1]]['price'] ?? null,
            'Sin precio en el grupo: se escribe a mano en el producto.');
    }

    public function test_product_form_fills_the_variants_from_the_group(): void
    {
        $group = VariantGroup::create(['name' => 'Tamaños']);
        $group->items()->createMany([
            ['name' => 'Mediana', 'code' => 'mediana', 'price' => 1050, 'sort_order' => 1],
            ['name' => 'Familiar', 'code' => 'familiar', 'price' => 1600, 'sort_order' => 2],
        ]);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Barbacoa', 'category_id' => Category::factory()->create()->id,
                'type' => ProductType::Variants->value, 'price' => '10,50', 'vat_rate' => 1000,
            ])
            ->fillForm(['variant_group_pick' => $group->id])
            ->assertSchemaStateSet(['variant_group_pick' => null])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Barbacoa')->sole();
        $this->assertSame([['mediana', 1050], ['familiar', 1600]], $product->variants->map(fn ($v) => [$v->code, $v->price])->all());
    }
}
