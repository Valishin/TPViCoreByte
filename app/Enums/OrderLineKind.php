<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de línea: normal, componente de menú o mitad de pizza. */
enum OrderLineKind: string implements HasLabel
{
    case Normal = 'normal';
    case MenuComponent = 'menu_componente';
    case Half = 'mitad';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::MenuComponent => 'Componente de menú',
            self::Half => 'Mitad',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
