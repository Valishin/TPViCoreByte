<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** "Nuevo PIN" → atributo pin (el modelo lo valida y lo hashea). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (filled($data['new_pin'] ?? null)) {
            $data['pin'] = $data['new_pin'];
        }
        unset($data['new_pin']);

        // Camareros y repartidores no entran al backoffice: contraseña aleatoria.
        $data['password'] ??= str()->random(40);

        return $data;
    }
}
