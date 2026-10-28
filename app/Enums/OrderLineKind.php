<?php

namespace App\Enums;

/** Tipo de línea: normal, componente de menú o mitad de pizza. */
enum OrderLineKind: string
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
}
