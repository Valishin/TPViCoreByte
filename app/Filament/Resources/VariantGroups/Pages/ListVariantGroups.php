<?php

namespace App\Filament\Resources\VariantGroups\Pages;

use App\Filament\Resources\VariantGroups\VariantGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVariantGroups extends ListRecords
{
    protected static string $resource = VariantGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
