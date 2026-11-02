<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Unguarded]
class Room extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Una sala desactivada no sale en el plano: con mesas en uso, sus pedidos quedarían ocultos.
        static::saving(function (Room $room): void {
            if ($room->exists && $room->isDirty('is_active') && ! $room->is_active) {
                $busy = $room->tables()->get()->filter(fn (Table $t) => $t->isInUse())->pluck('name');
                if ($busy->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'is_active' => 'La sala tiene mesas abiertas ('.$busy->implode(', ').'): cóbralas o libéralas antes de desactivarla.',
                    ]);
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class)->orderBy('name');
    }
}
