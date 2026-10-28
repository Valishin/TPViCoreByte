<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Forma de la mesa en el plano. */
enum TableShape: string implements HasLabel
{
    case Square = 'cuadrada';
    case Round = 'redonda';

    public function label(): string
    {
        return match ($this) {
            self::Square => 'Cuadrada',
            self::Round => 'Redonda',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
