<?php

namespace App\Enums;

/** Rol del personal. */
enum UserRole: string
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

    /** Roles que entran en el backoffice (Filament) con email y contraseña. */
    public function canAccessBackoffice(): bool
    {
        return $this === self::Admin || $this === self::Manager;
    }
}
