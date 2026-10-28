<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de producto de la carta. */
enum ProductType: string implements HasLabel
{
    case Simple = 'simple';
    case Variants = 'variantes';
    case Menu = 'menu';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple',
            self::Variants => 'Con variantes',
            self::Menu => 'Menú / combo',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
