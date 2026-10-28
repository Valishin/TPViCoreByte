<?php

namespace App\Filament\Components;

use App\Support\Format;
use Filament\Forms\Components\Select;

/** Tipo de IVA: el usuario ve "10 %", se guardan puntos básicos (1000). */
class VatRateSelect extends Select
{
    public const RATES = [0, 400, 500, 1000, 2100];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options(function (?int $state) {
            $rates = self::RATES;
            if ($state !== null && ! in_array($state, $rates, true)) {
                $rates[] = $state; // tipo guardado que ya no está en la lista
                sort($rates);
            }

            return collect($rates)->mapWithKeys(fn (int $rate) => [$rate => Format::percent($rate)])->all();
        })->native(false);
    }
}
