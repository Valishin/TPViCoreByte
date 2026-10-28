<?php

namespace App\Enums;

/** Forma de la mesa en el plano. */
enum TableShape: string
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
}
