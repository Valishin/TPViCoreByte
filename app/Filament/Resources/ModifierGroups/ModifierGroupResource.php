<?php

namespace App\Filament\Resources\ModifierGroups;

use App\Filament\Concerns\HasSentenceCaseLabels;
use App\Filament\Resources\ModifierGroups\Pages\CreateModifierGroup;
use App\Filament\Resources\ModifierGroups\Pages\EditModifierGroup;
use App\Filament\Resources\ModifierGroups\Pages\ListModifierGroups;
use App\Models\ModifierGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ModifierGroupResource extends Resource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = ModifierGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Carta';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'grupo de modificadores';

    protected static ?string $pluralModelLabel = 'grupos de modificadores';

    protected static ?string $slug = 'modificadores';

    protected static ?string $navigationLabel = 'Grupos de modificadores';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return Schemas\ModifierGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\ModifierGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModifierGroups::route('/'),
            'create' => CreateModifierGroup::route('/create'),
            'edit' => EditModifierGroup::route('/{record}/edit'),
        ];
    }
}
