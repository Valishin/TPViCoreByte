<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Método de rectificación. */
enum RectificationMethod: string implements HasLabel
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

    public function getLabel(): string
    {
        return $this->label();
    }
}
