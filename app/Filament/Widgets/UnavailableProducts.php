<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Aviso de productos marcados como agotados (no disponibles) que siguen activos. */
class UnavailableProducts extends TableWidget
{
    protected static ?int $sort = 3;

    protected static ?bool $isLazyLoaded = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Productos agotados';

    public function table(Table $table): Table
    {
        return $table
            ->query(Product::query()->with('category')->where('is_active', true)->where('is_available', false))
            ->heading('Productos agotados')
            ->description('Están ocultos en el TPV hasta que los vuelvas a marcar como disponibles.')
            ->emptyStateHeading('No hay productos agotados')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([5, 10])
            ->columns([
                TextColumn::make('name')->label('Producto')
                    ->url(fn (Product $record) => ProductResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('category.name')->label('Categoría'),
                TextColumn::make('updated_at')->label('Desde')->since(),
            ])
            ->recordActions([
                Action::make('makeAvailable')->label('Ya hay')->icon('heroicon-o-check')->color('success')
                    ->authorize(fn (Product $record) => auth()->user()->can('update', $record))
                    ->action(fn (Product $record) => $record->update(['is_available' => true])),
            ]);
    }
}
