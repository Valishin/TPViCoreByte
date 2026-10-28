<?php

namespace App\Filament\Components;

use App\Support\Format;
use Closure;
use Filament\Forms\Components\TextInput;

/** Porcentaje: el usuario escribe "15" o "12,5" y se guarda en puntos básicos (1500, 1250). */
class PercentInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->suffix('%')
            ->inputMode('decimal')
            ->formatStateUsing(fn ($state) => is_numeric($state) ? Format::percentInput((int) $state) : $state)
            ->dehydrateStateUsing(fn ($state) => blank($state) ? null : Format::parsePercent($state))
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                if (blank($value)) {
                    return;
                }
                $bp = Format::parsePercent($value);
                if ($bp === null || $bp < 0 || $bp > 10000) {
                    $fail('Escribe un porcentaje entre 0 y 100.');
                }
            });
    }
}
