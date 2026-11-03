<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\Allergen;
use App\Enums\ProductType;
use App\Filament\Components\MoneyInput;
use App\Filament\Components\VatRateSelect;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantGroup;
use App\Support\Format;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Producto')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    self::generalTab(),
                    self::variantsTab(),
                    self::modifiersTab(),
                    self::menuTab(),
                ]),
        ]);
    }

    private static function generalTab(): Tab
    {
        return Tab::make('General')->icon('heroicon-o-information-circle')->schema([
            Grid::make(3)->schema([
                TextInput::make('name')->label('Nombre')->required()->maxLength(255)->columnSpan(2),
                TextInput::make('short_name')->label('Nombre corto (cocina)')->maxLength(40)
                    ->helperText('Opcional. Lo que se imprime en la comanda.'),
                Select::make('category_id')->label('Categoría')
                    ->relationship('category', 'name')->required()->searchable()->preload(),
                ToggleButtons::make('type')->label('Tipo')
                    ->options(ProductType::class)->default(ProductType::Simple)
                    ->inline()->required()->live()->columnSpan(2),
            ]),
            Section::make('Precio e IVA')->columns(3)->schema([
                MoneyInput::make('price')
                    ->label(fn (Get $get) => self::isType($get, ProductType::Variants) ? 'Precio orientativo' : 'Precio')
                    ->helperText(fn (Get $get) => match (true) {
                        self::isType($get, ProductType::Variants) => 'Se cobra el precio de cada variante.',
                        self::isType($get, ProductType::Menu) => 'Precio del menú completo.',
                        default => 'IVA incluido.',
                    })
                    ->required()->default(0),
                VatRateSelect::make('vat_rate')->label('IVA en el local')->required()->default(1000),
                VatRateSelect::make('vat_rate_takeaway')->label('IVA para llevar / domicilio')
                    ->placeholder('El mismo que en el local')
                    ->helperText('Ej.: bebidas alcohólicas al 21 % para llevar.'),
            ]),
            Section::make('Cocina y carta')->columns(3)->schema([
                Select::make('printer_id')->label('Impresora')
                    ->relationship('printer', 'name')->placeholder('La de su categoría'),
                Toggle::make('is_active')->label('Activo')->default(true)->inline(false)
                    ->helperText('Si no está activo no aparece en el TPV.'),
                Toggle::make('is_available')->label('Disponible')->default(true)->inline(false)
                    ->helperText('Desmárcalo si se ha agotado hoy.'),
                Toggle::make('allows_half_and_half')->label('Admite mitad y mitad')->inline(false)
                    ->visible(fn (Get $get) => self::isType($get, ProductType::Variants)),
            ]),
            Section::make('Alérgenos')->schema([
                CheckboxList::make('allergens')->hiddenLabel()
                    ->options(collect(Allergen::cases())->mapWithKeys(fn (Allergen $a) => [$a->value => $a->emoji().' '.$a->label()]))
                    ->formatStateUsing(fn ($state) => collect($state)->map(fn ($a) => $a instanceof Allergen ? $a->value : $a)->values()->all())
                    ->columns(['default' => 2, 'md' => 4])
                    ->gridDirection('row')
                    ->bulkToggleable(),
            ]),
        ]);
    }

    private static function variantsTab(): Tab
    {
        return Tab::make('Variantes')->icon('heroicon-o-arrows-pointing-out')
            ->visible(fn (Get $get) => self::isType($get, ProductType::Variants))
            ->schema([
                // Las de un grupo (Carta → Grupos de variantes) se copian aquí: siguen siendo de este
                // producto y se pueden cambiar; sin precio en el grupo, se escribe aquí.
                Select::make('variant_group_pick')->label('Añadir variantes de un grupo')
                    ->placeholder('Elige un grupo…')
                    ->options(fn () => VariantGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->dehydrated(false)->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        if ($state) {
                            $set('variants', self::withGroupVariants($get('variants') ?? [], (int) $state));
                            $set('variant_group_pick', null);
                        }
                    })
                    ->helperText('Añade las que falten (sin repetir el código de tamaño). Los grupos se crean en Carta → Grupos de variantes.'),
                Repeater::make('variants')->hiddenLabel()
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->minItems(1)
                    ->addActionLabel('Añadir variante')
                    ->table([
                        TableColumn::make('Nombre')->markAsRequired(),
                        TableColumn::make('Código de tamaño')->markAsRequired(),
                        TableColumn::make('Precio')->markAsRequired()->width('180px'),
                        TableColumn::make('Activa')->width('90px'),
                    ])
                    ->compact()
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required()->placeholder('Familiar'),
                        TextInput::make('code')->label('Código de tamaño')->required()->distinct()
                            ->datalist(['individual', 'mediana', 'familiar', 'grande', 'media', 'entera'])
                            ->alphaDash(),
                        MoneyInput::make('price')->label('Precio')->required(),
                        Toggle::make('is_active')->label('Activa')->default(true),
                    ])
                    ->helperText('El código de tamaño debe ser igual en todas las pizzas (mediana, familiar…): sirve para la mitad y mitad y para los extras por tamaño.'),
            ]);
    }

    /** Variantes del formulario (sin filas vacías) + las del grupo que falten (por código de tamaño). */
    public static function withGroupVariants(array $variants, int $groupId): array
    {
        // La fila vacía con la que empieza la tabla no se queda colgando.
        $variants = array_filter($variants, fn ($v) => filled($v['name'] ?? null) || filled($v['code'] ?? null) || filled($v['price'] ?? null));
        $codes = collect($variants)->pluck('code')->filter()->map(fn ($c) => mb_strtolower((string) $c))->all();
        $group = VariantGroup::query()->with('items')->find($groupId);
        foreach ($group?->items ?? [] as $item) {
            if (in_array(mb_strtolower($item->code), $codes, true)) {
                continue;
            }
            $variants[(string) Str::uuid()] = [
                'name' => $item->name,
                'code' => $item->code,
                'price' => $item->price !== null ? Format::moneyInput($item->price) : null,   // vacío: a mano
                'is_active' => true,
            ];
        }

        return $variants;
    }

    private static function modifiersTab(): Tab
    {
        return Tab::make('Modificadores')->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (Get $get) => ! self::isType($get, ProductType::Menu))
            ->schema([
                CheckboxList::make('modifierGroups')->label('Grupos de modificadores')
                    ->relationship('modifierGroups', 'name')
                    ->descriptions(fn () => ModifierGroup::query()->withCount('modifiers')->get()->mapWithKeys(fn (ModifierGroup $g) => [
                        $g->id => self::choiceText($g->min_select, $g->max_select).' · '.$g->modifiers_count.' opciones',
                    ])->all())
                    ->columns(2)
                    ->helperText('Los grupos se crean en Carta → Grupos de modificadores.'),
            ]);
    }

    private static function menuTab(): Tab
    {
        return Tab::make('Menú')->icon('heroicon-o-queue-list')
            ->visible(fn (Get $get) => self::isType($get, ProductType::Menu))
            ->schema([
                Repeater::make('menuGroups')->label('Grupos de elección')
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->minItems(1)
                    ->addActionLabel('Añadir grupo (p. ej. "Bebida")')
                    ->itemLabel(fn (array $state) => $state['name'] ?? null)
                    ->collapsible()
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('name')->label('Nombre del grupo')->required()->placeholder('Bebida'),
                            TextInput::make('min_select')->label('Mínimo a elegir')->numeric()->minValue(0)->default(1)->required(),
                            TextInput::make('max_select')->label('Máximo a elegir')->numeric()->minValue(1)->default(1)->required()
                                ->gte('min_select'),
                        ]),
                        Repeater::make('items')->label('Productos que se pueden elegir')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->minItems(1)
                            ->addActionLabel('Añadir producto')
                            ->table([
                                TableColumn::make('Producto')->markAsRequired(),
                                TableColumn::make('Variante'),
                                TableColumn::make('Suplemento')->width('160px'),
                            ])
                            ->compact()
                            ->schema([
                                Select::make('product_id')->label('Producto')->required()->searchable()->live()
                                    ->options(fn () => Product::query()->where('type', '!=', ProductType::Menu)->orderBy('name')->pluck('name', 'id'))
                                    ->afterStateUpdated(fn ($set) => $set('product_variant_id', null)),
                                Select::make('product_variant_id')->label('Variante')
                                    ->options(fn (Get $get) => ProductVariant::query()->where('product_id', $get('product_id'))->orderBy('sort_order')->pluck('name', 'id'))
                                    ->placeholder('—')
                                    ->disabled(fn (Get $get) => ! ($get('product_id') && ProductVariant::query()->where('product_id', $get('product_id'))->exists()))
                                    ->required(fn (Get $get) => $get('product_id') && ProductVariant::query()->where('product_id', $get('product_id'))->exists()),
                                MoneyInput::make('supplement')->label('Suplemento')->default(0),
                            ]),
                    ]),
            ]);
    }

    public static function choiceText(int $min, ?int $max): string
    {
        return match (true) {
            $max === null => $min > 0 ? "Obligatorio, al menos {$min}" : 'Opcional, sin límite',
            $min === $max => ($min > 0 ? 'Obligatorio, ' : 'Opcional, ')."elegir {$max}",
            $min > 0 => "Obligatorio, de {$min} a {$max}",
            default => "Opcional, hasta {$max}",
        };
    }

    private static function isType(Get $get, ProductType $type): bool
    {
        $value = $get('type');

        return ($value instanceof ProductType ? $value : ProductType::tryFrom((string) $value)) === $type;
    }

    /** Texto con los precios de las variantes ("Mediana 9,50 € · Familiar 15,00 €"). */
    public static function variantPrices(Product $product): ?string
    {
        return $product->variants->isEmpty() ? null
            : $product->variants->map(fn ($v) => $v->name.' '.Format::money($v->price))->implode(' · ');
    }
}
