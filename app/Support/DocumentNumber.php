<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Número de un documento fiscal: {SERIE}-{AA}-{NNNNNN} (T-26-000001,
 * FR-26-000001, Z-26-0001).
 *
 * Art. 6.1.a RD 1619/2012: "número y, en su caso, serie. La numeración de las
 * facturas dentro de cada serie será correlativa". La numeración se reinicia
 * cada año natural e incluye el año, así que cada (serie, año) es una
 * secuencia correlativa sin huecos (la lleva TicketNumberService con el
 * contador bloqueado dentro de la transacción de emisión) y el número completo
 * no se repite nunca: clave única (serie, año, número).
 *
 * El año es el de la fecha de emisión en hora peninsular (Europe/Madrid,
 * config tpv.fiscal_timezone), no el de la caja: un ticket del 1 de enero a
 * las 00:30 es el 1 del año nuevo aunque la caja se abriera el 31.
 *
 * El número completo se guarda formateado en el documento (full_number): es un
 * snapshot y un cambio futuro de formato o de cifras no altera lo emitido.
 *
 * VERI*FACTU (fase siguiente): NumSerieFactura será este número completo
 * formateado (full_number), tal cual está en el documento.
 */
final class DocumentNumber
{
    public const DEFAULT_DIGITS = 6;

    public const MIN_DIGITS = 5;

    public function __construct(
        public readonly string $series,
        public readonly int $year,
        public readonly int $number,
        public readonly string $full,
    ) {}

    public static function make(string $series, int $year, int $number, int $digits = self::DEFAULT_DIGITS): self
    {
        return new self($series, $year, $number, self::format($series, $year, $number, $digits));
    }

    public static function format(string $series, int $year, int $number, int $digits = self::DEFAULT_DIGITS): string
    {
        return sprintf('%s-%02d-%0'.max(1, $digits).'d', $series, $year % 100, $number);
    }

    public static function timezone(): string
    {
        return (string) config('tpv.fiscal_timezone', 'Europe/Madrid');
    }

    /** Año natural de una fecha de emisión, en hora fiscal (Europe/Madrid). */
    public static function yearOf(CarbonInterface $at): int
    {
        return (int) $at->copy()->setTimezone(self::timezone())->format('Y');
    }

    /** Columnas del documento: year, number y full_number (snapshot). */
    public function attributes(): array
    {
        return ['year' => $this->year, 'number' => $this->number, 'full_number' => $this->full];
    }
}
