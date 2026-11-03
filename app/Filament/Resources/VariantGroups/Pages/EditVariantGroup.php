<?php

namespace App\Filament\Resources\VariantGroups\Pages;

use App\Filament\Resources\VariantGroups\VariantGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVariantGroup extends EditRecord
{
    protected static string $resource = VariantGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
