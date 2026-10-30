<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de identificador fiscal del destinatario de una factura (art. 6.1.d). */
enum TaxIdType: string implements HasLabel
{
    case Nif = 'nif';       // persona física española (DNI + letra)
    case Nie = 'nie';       // extranjero residente (X/Y/Z…)
    case Cif = 'cif';       // persona jurídica o entidad española
    case VatEu = 'vat_eu';  // NIF-IVA intracomunitario (solo formato)
    case Other = 'otro';    // pasaporte u otro documento extranjero (IDOtro en VERI*FACTU)

    public function label(): string
    {
        return match ($this) {
            self::Nif => 'NIF',
            self::Nie => 'NIE',
            self::Cif => 'CIF',
            self::VatEu => 'NIF-IVA (UE)',
            self::Other => 'Pasaporte u otro documento',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
