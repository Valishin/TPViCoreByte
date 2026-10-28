<?php

namespace App\Enums;

/** Estado de reparto (para llevar / domicilio). */
enum DeliveryStatus: string
{
    case Pending = 'pendiente';
    case InKitchen = 'en_cocina';
    case OutForDelivery = 'en_reparto';
    case Delivered = 'entregado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::InKitchen => 'En cocina',
            self::OutForDelivery => 'En reparto',
            self::Delivered => 'Entregado',
        };
    }
}
