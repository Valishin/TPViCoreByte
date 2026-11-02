<?php

namespace App\Filament\Resources\DeliveryZones\Pages;

use App\Filament\Resources\DeliveryZones\DeliveryZoneResource;
use App\Filament\Resources\DeliveryZones\Schemas\DeliveryZoneForm;
use Filament\Resources\Pages\CreateRecord;

class CreateDeliveryZone extends CreateRecord
{
    protected static string $resource = DeliveryZoneResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return DeliveryZoneForm::fromForm($data);
    }
}
