<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Motivo de una factura rectificativa (art. 15.1 y 15.2 RD 1619/2012). Se
 * guarda como código para fijar el tipo R1-R4 de VERI*FACTU.
 *
 * DUDA (se cierra en la fase de VERI*FACTU): la frontera entre R1 "error
 * fundado en derecho" y R4 "resto" en las correcciones de datos formales.
 */
enum RectificationReason: string implements HasLabel
{
    case ErrorOrDiscount = 'error_descuento';   // errores, devoluciones, descuentos posteriores (art. 80 Uno, Dos, Seis)
    case Insolvency = 'concurso';               // art. 80.Tres LIVA
    case BadDebt = 'incobrable';                // art. 80.Cuatro LIVA
    case RecipientData = 'datos_destinatario';  // corrección de datos del destinatario (art. 15.1: no cumple art. 6)
    case Other = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::ErrorOrDiscount => 'Error, devolución o descuento posterior',
            self::Insolvency => 'Concurso del destinatario',
            self::BadDebt => 'Crédito incobrable',
            self::RecipientData => 'Corrección de los datos del destinatario',
            self::Other => 'Otro motivo',
        };
    }

    public function verifactuType(): string
    {
        return match ($this) {
            self::ErrorOrDiscount => 'R1',
            self::Insolvency => 'R2',
            self::BadDebt => 'R3',
            self::RecipientData, self::Other => 'R4', // DUDA: R1 o R4 (ver arriba)
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
