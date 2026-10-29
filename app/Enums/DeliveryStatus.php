<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Estado de un pedido para llevar / a domicilio.
 *   Pendiente → En cocina (al enviar la comanda) → Listo → En reparto (domicilio) → Entregado
 * Para llevar va de Listo a Entregado. "No entregado": domicilio que vuelve sin
 * cobrar (rectificativo total).
 */
enum DeliveryStatus: string implements HasLabel
{
    case Pending = 'pendiente';
    case InKitchen = 'en_cocina';
    case Ready = 'listo';
    case OutForDelivery = 'en_reparto';
    case Delivered = 'entregado';
    case Failed = 'no_entregado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::InKitchen => 'En cocina',
            self::Ready => 'Listo',
            self::OutForDelivery => 'En reparto',
            self::Delivered => 'Entregado',
            self::Failed => 'No entregado',
        };
    }

    /** Ya no está en el tablero activo. */
    public function isFinished(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
