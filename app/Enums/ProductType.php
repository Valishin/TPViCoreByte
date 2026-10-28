<?php

namespace App\Enums;

/** Tipo de producto de la carta. */
enum ProductType: string
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
}
