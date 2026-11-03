<?php

namespace Tests\Feature\Filament;

use App\Enums\ProductType;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

class ProductResourceTest extends FilamentTestCase
{
    public function test_money_field_stores_cents(): void
    {
        $category = Category::factory()->create();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Tortilla',
                'category_id' => $category->id,
                'type' => ProductType::Simple->value,
                'price' => '12,50',
                'vat_rate' => 1000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('name', 'Tortilla')->firstOrFail();
        $this->assertSame(1250, $product->price);

        // Y al editar se muestra en euros con coma.
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertSchemaStateSet(['price' => '12,50']);
    }

    public function test_invalid_money_is_rejected(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Caña',
                'category_id' => Category::factory()->create()->id,
                'type' => ProductType::Simple->value,
                'price' => '2,505',
                'vat_rate' => 1000,
            ])
            ->call('create')
            ->assertHasFormErrors(['price']);
    }

    public function test_create_product_with_variants(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $extras = ModifierGroup::factory()->create();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Barbacoa',
                'category_id' => Category::factory()->create()->id,
                'type' => ProductType::Variants->value,
                'price' => '10,50',
                'vat_rate' => 1000,
                'allows_half_and_half' => true,
                'half_and_half_surcharge' => '1,50',
                'allergens' => ['gluten', 'lacteos'],
                'variants' => [
                    ['name' => 'Mediana', 'code' => 'mediana', 'price' => '10,50', 'is_active' => true],
                    ['name' => 'Familiar', 'code' => 'familiar', 'price' => '16', 'is_active' => true],
                ],
                'modifierGroups' => [$extras->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $product = Product::where('name', 'Barbacoa')->firstOrFail();
        $this->assertSame([['mediana', 1050], ['familiar', 1600]], $product->variants->map(fn ($v) => [$v->code, $v->price])->all());
        $this->assertTrue($product->allows_half_and_half);
        $this->assertSame(150, $product->half_and_half_surcharge);
        $this->assertSame(['gluten', 'lacteos'], $product->allergens->map->value->all());
        $this->assertTrue($product->modifierGroups->contains($extras));
    }

    public function test_variants_product_requires_at_least_one_variant(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Pizza sin tamaños',
                'category_id' => Category::factory()->create()->id,
                'type' => ProductType::Variants->value,
                'price' => '10',
                'vat_rate' => 1000,
                'variants' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['variants']);

        $undoRepeaterFake();
    }

    public function test_create_menu_with_choice_groups(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $pizza = Product::factory()->pizza(900, 1400)->create(['name' => 'Margarita']);
        $cola = Product::factory()->price(200)->create(['name' => 'Cola']);
        $beer = Product::factory()->price(220)->create(['name' => 'Cerveza']);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Menú Individual',
                'category_id' => Category::factory()->create()->id,
                'type' => ProductType::Menu->value,
                'price' => '12,90',
                'vat_rate' => 1000,
                'menuGroups' => [
                    ['name' => 'Pizza', 'min_select' => 1, 'max_select' => 1, 'items' => [
                        ['product_id' => $pizza->id, 'product_variant_id' => $pizza->variants->firstWhere('code', 'mediana')->id, 'supplement' => '0'],
                    ]],
                    ['name' => 'Bebida', 'min_select' => 1, 'max_select' => 1, 'items' => [
                        ['product_id' => $cola->id, 'supplement' => '0'],
                        ['product_id' => $beer->id, 'supplement' => '0,50'],
                    ]],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $menu = Product::where('name', 'Menú Individual')->with('menuGroups.items')->firstOrFail();
        $this->assertSame(1290, $menu->price);
        $this->assertSame(['Pizza', 'Bebida'], $menu->menuGroups->pluck('name')->all());
        $this->assertSame([0, 50], $menu->menuGroups[1]->items->pluck('supplement')->all());
        $this->assertNotNull($menu->menuGroups[0]->items[0]->product_variant_id);
    }

    public function test_availability_toggle_from_table(): void
    {
        $product = Product::factory()->create(['is_available' => true]);

        Livewire::test(ListProducts::class)
            ->call('updateTableColumnState', 'is_available', (string) $product->id, false);

        $this->assertFalse($product->fresh()->is_available);
    }

    public function test_duplicate_creates_inactive_copy_with_variants(): void
    {
        $pizza = Product::factory()->pizza(900, 1400)->create(['name' => 'Vegetal']);
        $pizza->modifierGroups()->attach(ModifierGroup::factory()->create());

        Livewire::test(ListProducts::class)
            ->callAction(TestAction::make('duplicate')->table($pizza));

        $copy = Product::where('name', 'Vegetal (copia)')->firstOrFail();
        $this->assertFalse($copy->is_active);
        $this->assertSame(2, $copy->variants()->count());
        $this->assertSame(1, $copy->modifierGroups()->count());
        $this->assertTrue($pizza->fresh()->is_active);
    }
}
