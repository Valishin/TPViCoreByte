<?php

namespace App\Services\Verifactu;

use App\Models\FiscalRecord;
use App\Models\Setting;
use App\Models\Ticket;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Código «QR» tributario (Orden HAC/1177/2024 arts. 20 y 21; «Detalle de las
 * especificaciones técnicas del código QR» v0.5.0).
 *
 * - URL de cotejo con 4 parámetros (apdo. 6): nif, numserie, fecha (dd-mm-aaaa)
 *   e importe (punto decimal), con «URL encoding» UTF-8 (apdo. 4). Sin el
 *   parámetro «formato» (apdo. 7). Pruebas: prewww2.aeat.es; producción:
 *   www2.agenciatributaria.gob.es (apdo. 5.1).
 * - ISO/IEC 18004, nivel de corrección M, entre 30x30 y 40x40 mm (OM 21.1),
 *   con margen en blanco de al menos 2 mm (apdo. 3; usamos 4 módulos).
 * - Va al principio del documento, con «QR tributario:» encima y la frase
 *   «VERI*FACTU» o «Factura verificable en la sede electrónica de la AEAT»
 *   debajo, en letra no menor que el resto (OM 20.1; apdo. 3).
 *
 * Solo llevan QR los documentos con registro de facturación de alta (emitidos
 * con VERI*FACTU activo).
 */
final class TaxQr
{
    public const LEGEND_SHORT = 'VERI*FACTU';

    public const LEGEND_LONG = 'Factura verificable en la sede electrónica de la AEAT';

    /** Tamaño objetivo del código en papel (dentro de 30-40 mm). */
    public const TARGET_MM = 35;

    public static function for(Ticket $ticket): ?string
    {
        $record = FiscalRecord::query()->where('ticket_id', $ticket->id)->where('record_type', FiscalRecord::ALTA)->orderBy('id')->first();

        return $record ? self::url($record) : null;
    }

    /** URL de cotejo con los datos del registro (los mismos que conoce la AEAT). */
    public static function url(FiscalRecord $record, ?string $environment = null): string
    {
        $base = config('verifactu.qr_url.'.($environment ?? Verifactu::environment(Setting::current())));

        return $base.'?'.implode('&', [
            'nif='.rawurlencode($record->issuer_nif),
            'numserie='.rawurlencode($record->invoice_number),
            'fecha='.rawurlencode($record->issue_date),
            'importe='.rawurlencode(Verifactu::amount((int) $record->amount_total)),
        ]);
    }

    /** Matriz del código (nivel M). */
    public static function matrix(string $url): QRMatrix
    {
        return (new QRCode(new QROptions(['eccLevel' => EccLevel::M])))->addByteSegment($url)->getQRMatrix();
    }

    /**
     * Tamaño del módulo en puntos para que el código quede entre 30 y 40 mm
     * (lo más cerca posible de TARGET_MM) a $dotsPerMm puntos por milímetro.
     *
     * @return array{module: int, modules: int, mm: float}
     */
    public static function sizing(string $url, int $dotsPerMm = 8): array
    {
        $modules = self::matrix($url)->getSize();
        $module = max(1, (int) round(self::TARGET_MM * $dotsPerMm / $modules));
        while ($module * $modules > 40 * $dotsPerMm && $module > 1) {
            $module--;
        }
        while ($module * $modules < 30 * $dotsPerMm) {
            $module++;
        }

        return ['module' => $module, 'modules' => $modules, 'mm' => round($module * $modules / $dotsPerMm, 2)];
    }

    /** PNG del código con 4 módulos de margen blanco, al tamaño de sizing(). */
    public static function png(string $url, int $dotsPerMm = 8): string
    {
        $size = self::sizing($url, $dotsPerMm);
        $options = new QROptions([
            'eccLevel' => EccLevel::M,
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
            'scale' => $size['module'],
            'addQuietzone' => true,
            'quietzoneSize' => 4,
            'drawLightModules' => true,
        ]);

        return (new QRCode($options))->render($url);
    }
}
