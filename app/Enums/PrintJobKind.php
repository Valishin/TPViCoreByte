<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Qué se imprime. */
enum PrintJobKind: string implements HasLabel
{
    case Kitchen = 'comanda';
    case Void = 'anulacion';
    case Precheck = 'cuenta';
    case Receipt = 'ticket';
    case PaymentReceipt = 'justificante';
    case Drawer = 'cajon';
    case Report = 'informe';

    public function label(): string
    {
        return match ($this) {
            self::Kitchen => 'Comanda',
            self::Void => 'Anulación',
            self::Precheck => 'Cuenta',
            self::Receipt => 'Ticket',
            self::PaymentReceipt => 'Justificante de pago',
            self::Drawer => 'Apertura de cajón',
            self::Report => 'Informe de caja',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
