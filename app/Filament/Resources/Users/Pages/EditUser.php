<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** "Nuevo PIN" → atributo pin (el modelo lo valida y lo hashea). */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (filled($data['new_pin'] ?? null)) {
            $data['pin'] = $data['new_pin'];
        }
        unset($data['new_pin']);

        return $data;
    }
}
