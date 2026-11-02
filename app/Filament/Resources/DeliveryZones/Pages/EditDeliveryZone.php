<?php

namespace App\Filament\Resources\DeliveryZones\Pages;

use App\Filament\Resources\DeliveryZones\DeliveryZoneResource;
use App\Filament\Resources\DeliveryZones\Schemas\DeliveryZoneForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDeliveryZone extends EditRecord
{
    protected static string $resource = DeliveryZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return DeliveryZoneForm::toForm($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return DeliveryZoneForm::fromForm($data);
    }
}
