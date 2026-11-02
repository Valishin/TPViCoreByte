<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\Rooms\Schemas\RoomForm;
use App\Models\Room;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Crea de una vez las salas marcadas (Salón, Barra, Terraza) y la personalizada. */
class CreateRoom extends CreateRecord
{
    protected static string $resource = RoomResource::class;

    /** @var list<string> */
    public array $createdNames = [];

    protected function handleRecordCreation(array $data): Model
    {
        $custom = trim((string) ($data['custom'] ?? ''));
        if ($custom !== '' && RoomForm::exists($custom)) {
            throw ValidationException::withMessages(['data.custom' => "Ya hay una sala «{$custom}»."]);
        }
        $names = collect(RoomForm::PRESETS)->intersect($data['presets'] ?? [])
            ->reject(fn (string $name) => RoomForm::exists($name))
            ->push($custom)->filter()->unique(fn (string $name) => mb_strtolower($name))->values();
        if ($names->isEmpty()) {
            throw ValidationException::withMessages(['data.presets' => 'Marca alguna sala o escribe una personalizada.']);
        }

        $rooms = DB::transaction(function () use ($names, $data) {
            $order = (int) Room::query()->max('sort_order');

            return $names->map(fn (string $name) => Room::create([
                'name' => $name, 'is_active' => $data['is_active'] ?? true, 'sort_order' => ++$order,
            ]));
        });
        $this->createdNames = $names->all();

        return $rooms->first();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return count($this->createdNames) > 1
            ? 'Salas creadas: '.implode(', ', $this->createdNames)
            : 'Sala creada';
    }

    /** Una sala: a editarla para añadir las mesas. Varias: a la lista. */
    protected function getRedirectUrl(): string
    {
        return count($this->createdNames) > 1
            ? $this->getResource()::getUrl('index')
            : $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
