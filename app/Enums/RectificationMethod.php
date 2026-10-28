<?php

namespace App\Enums;

/** Método de rectificación. */
enum RectificationMethod: string
{
    case Substitution = 'sustitucion';
    case Differences = 'diferencias';

    public function label(): string
    {
        return match ($this) {
            self::Substitution => 'Por sustitución',
            self::Differences => 'Por diferencias',
        };
    }
}
