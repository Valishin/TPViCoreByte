<?php

namespace App\Filament\Resources\ModifierGroups\Schemas;

use App\Filament\Components\MoneyInput;
use App\Models\ProductVariant;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ModifierGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->columnSpanFull()->schema([
                TextInput::make('name')->label('Nombre del grupo')->required()->maxLength(255)
                    ->placeholder('Punto de la carne, Ingredientes extra…'),
                TextInput::make('min_select')->label('Mínimo de elecciones')->numeric()->integer()->minValue(0)->default(0)->required()
                    ->helperText('0 = opcional. 1 o más = obligatorio.'),
                TextInput::make('max_select')->label('Máximo de elecciones')->numeric()->integer()->minValue(1)
                    ->placeholder('Sin límite')->gte('min_select'),
            ]),
            Section::make('Opciones')->columnSpanFull()->schema([
                Repeater::make('modifiers')->hiddenLabel()
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->addActionLabel('Añadir opción')
                    ->itemLabel(fn (array $state) => $state['name'] ?? null)
                    ->collapsible()
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')->label('Opción')->required()->placeholder('Extra de queso'),
                        MoneyInput::make('price_delta')->label('Incremento de precio')->default(0)->allowNegative()
                            ->helperText('0 si no cambia el precio.'),
                        Toggle::make('is_active')->label('Activa')->default(true)->inline(false),
                        Repeater::make('variantPrices')->label('Precio distinto por tamaño (opcional)')
                            ->relationship()
                            ->addActionLabel('Añadir precio por tamaño')
                            ->defaultItems(0)
                            ->columns(2)
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('variant_code')->label('Código de tamaño')->required()->distinct()
                                    ->datalist(fn () => ProductVariant::query()->distinct()->orderBy('code')->pluck('code')->all())
                                    ->placeholder('familiar'),
                                MoneyInput::make('price_delta')->label('Incremento en ese tamaño')->required()->allowNegative(),
                            ]),
                    ]),
            ]),
        ]);
    }
}
