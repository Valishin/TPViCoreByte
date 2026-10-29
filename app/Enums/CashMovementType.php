<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Movimientos de efectivo del cajón que no son ventas. */
enum CashMovementType: string implements HasLabel
{
    case CourierFloat = 'fondo_repartidor';       // sale: fondo de cambio que se lleva el repartidor
    case CourierFloatReturn = 'devolucion_fondo'; // entra: lo devuelve al liquidar

    public function label(): string
    {
        return match ($this) {
            self::CourierFloat => 'Fondo entregado a repartidor',
            self::CourierFloatReturn => 'Fondo devuelto por repartidor',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
