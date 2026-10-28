<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductType;
use App\Filament\Components\MoneyColumn;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use App\Services\Catalog\ProductDuplicator;
use App\Support\Format;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'variants']))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->groups([
                Group::make('category.name')->label('Categoría')->collapsible(),
            ])
            ->columns([
                TextColumn::make('name')->label('Producto')->searchable()->sortable()
                    ->description(fn (Product $record) => ProductForm::variantPrices($record)),
                TextColumn::make('category.name')->label('Categoría')->sortable()->toggleable(),
                TextColumn::make('type')->label('Tipo')->badge()
                    ->color(fn (ProductType $state) => match ($state) {
                        ProductType::Simple => 'gray',
                        ProductType::Variants => 'info',
                        ProductType::Menu => 'success',
                    }),
                MoneyColumn::make('price')->label('Precio')->sortable(),
                TextColumn::make('vat_rate')->label('IVA')->alignEnd()
                    ->formatStateUsing(fn (Product $record) => Format::percent($record->vat_rate)
                        .($record->vat_rate_takeaway !== null ? ' / '.Format::percent($record->vat_rate_takeaway).' llevar' : '')),
                TextColumn::make('allergens')->label('Alérgenos')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (Product $record) => $record->allergens?->map->emoji()->implode(' ') ?: '—')
                    ->tooltip(fn (Product $record) => $record->allergens?->map->label()->implode(', ')),
                ToggleColumn::make('is_available')->label('Disponible')
                    ->disabled(fn (Product $record) => ! auth()->user()->can('update', $record)),
                IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('Categoría')->relationship('category', 'name')->preload(),
                SelectFilter::make('type')->label('Tipo')->options(ProductType::class),
                TernaryFilter::make('is_available')->label('Disponibilidad')
                    ->trueLabel('Disponibles')->falseLabel('Agotados'),
                TernaryFilter::make('is_active')->label('Activos'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('duplicate')
                    ->label('Duplicar')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->authorize(fn (Product $record) => auth()->user()->can('replicate', $record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Product $record) => "Duplicar «{$record->name}»")
                    ->modalDescription('Se creará una copia (variantes, modificadores y grupos del menú incluidos) como NO activa, para que la revises antes de que aparezca en el TPV.')
                    ->modalSubmitActionLabel('Duplicar')
                    ->action(function (Product $record, Action $action) {
                        $copy = app(ProductDuplicator::class)->duplicate($record);
                        Notification::make()->success()->title("Creado «{$copy->name}»")->send();
                        $action->redirect(ProductResource::getUrl('edit', ['record' => $copy]));
                    }),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markUnavailable')->label('Marcar como agotados')->icon('heroicon-o-no-symbol')
                        ->authorize(fn () => auth()->user()->can('create', Product::class))
                        ->action(fn (Collection $records) => $records->each->update(['is_available' => false]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('markAvailable')->label('Marcar como disponibles')->icon('heroicon-o-check')
                        ->authorize(fn () => auth()->user()->can('create', Product::class))
                        ->action(fn (Collection $records) => $records->each->update(['is_available' => true]))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
