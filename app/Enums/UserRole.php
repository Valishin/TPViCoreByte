<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Rol del personal. */
enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case Manager = 'encargado';
    case Waiter = 'camarero';
    case Courier = 'repartidor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Manager => 'Encargado',
            self::Waiter => 'Camarero',
            self::Courier => 'Repartidor',
        };
    }

    /** Roles que usan el TPV de sala (los repartidores tendrán su propia pantalla). */
    public function canUsePos(): bool
    {
        return $this !== self::Courier;
    }

    /** Roles que entran en el backoffice (Filament) con email y contraseña. */
    public function canAccessBackoffice(): bool
    {
        return $this === self::Admin || $this === self::Manager;
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
