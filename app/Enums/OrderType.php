<?php

namespace App\Enums;

/** Tipo de pedido. Cada local activa los suyos en settings.enabled_order_types. */
enum OrderType: string
{
    case Table = 'mesa';
    case Bar = 'barra';
    case Takeaway = 'para_llevar';
    case Delivery = 'domicilio';

    public function label(): string
    {
        return match ($this) {
            self::Table => 'Mesa',
            self::Bar => 'Barra',
            self::Takeaway => 'Para llevar',
            self::Delivery => 'Domicilio',
        };
    }

    /** Pedidos que salen del local: aplican products.vat_rate_takeaway si existe. */
    public function isTakeaway(): bool
    {
        return $this === self::Takeaway || $this === self::Delivery;
    }
}
