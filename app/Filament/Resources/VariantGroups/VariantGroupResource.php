<?php

namespace App\Filament\Resources\VariantGroups;

use App\Filament\Components\MoneyInput;
use App\Filament\Concerns\HasSentenceCaseLabels;
use App\Filament\Resources\VariantGroups\Pages\CreateVariantGroup;
use App\Filament\Resources\VariantGroups\Pages\EditVariantGroup;
use App\Filament\Resources\VariantGroups\Pages\ListVariantGroups;
use App\Models\VariantGroup;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Grupos de variantes («Tamaños de pizza»: Individual, Mediana, Familiar…). En
 * el producto (pestaña Variantes) se elige un grupo y sus filas se copian como
 * variantes del producto, con su precio si lo tienen o para ponerlo a mano.
 */
class VariantGroupResource extends Resource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = VariantGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsPointingOut;

    protected static string|UnitEnum|null $navigationGroup = 'Carta';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'grupo de variantes';

    protected static ?string $pluralModelLabel = 'grupos de variantes';

    protected static ?string $slug = 'variantes';

    protected static ?string $navigationLabel = 'Grupos de variantes';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                TextInput::make('name')->label('Nombre del grupo')->required()->maxLength(255)->placeholder('Tamaños de pizza'),
                Repeater::make('items')->label('Variantes')
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->minItems(1)
                    ->addActionLabel('Añadir variante')
                    ->table([
                        TableColumn::make('Nombre')->markAsRequired(),
                        TableColumn::make('Código de tamaño')->markAsRequired(),
                        TableColumn::make('Precio (opcional)')->width('200px'),
                    ])
                    ->compact()
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required()->maxLength(255)->placeholder('Familiar'),
                        TextInput::make('code')->label('Código de tamaño')->required()->distinct()->alphaDash()->maxLength(40)
                            ->datalist(['individual', 'mediana', 'familiar', 'grande', 'media', 'entera']),
                        MoneyInput::make('price')->label('Precio (opcional)'),
                    ])
                    ->helperText('Precio vacío = se escribe en cada producto. El código de tamaño debe ser el mismo en toda la carta '
                        .'(mediana, familiar…): sirve para la mitad y mitad y para los extras por tamaño.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Grupo')->weight('bold')->searchable(),
                TextColumn::make('items_summary')->label('Variantes')->wrap()
                    ->state(fn (VariantGroup $record) => $record->items->map(fn ($i) => $i->name.($i->price !== null ? ' ('.Format::money($i->price).')' : ''))->implode(' · ')),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('items'))
            ->recordActions([EditAction::make(), DeleteAction::make()->modalDescription('Los productos que ya tienen estas variantes las conservan.')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVariantGroups::route('/'),
            'create' => CreateVariantGroup::route('/create'),
            'edit' => EditVariantGroup::route('/{record}/edit'),
        ];
    }
}
