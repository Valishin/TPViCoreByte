<?php

namespace Tests\Unit;

use App\Services\Verifactu\Fingerprint;
use PHPUnit\Framework\TestCase;

/** Ejemplos oficiales del apartado 6 de las «Especificaciones técnicas de la huella» (v0.1.2): deben coincidir exactamente. */
class VerifactuFingerprintTest extends TestCase
{
    public function test_official_example_1_first_record(): void
    {
        $values = ['IDEmisorFactura' => '89890001K', 'NumSerieFactura' => '12345678/G33', 'FechaExpedicionFactura' => '01-01-2024',
            'TipoFactura' => 'F1', 'CuotaTotal' => '12.35', 'ImporteTotal' => '123.45', 'Huella' => null, 'FechaHoraHusoGenRegistro' => '2024-01-01T19:20:30+01:00'];

        $this->assertSame(
            'IDEmisorFactura=89890001K&NumSerieFactura=12345678/G33&FechaExpedicionFactura=01-01-2024&TipoFactura=F1&CuotaTotal=12.35&ImporteTotal=123.45&Huella=&FechaHoraHusoGenRegistro=2024-01-01T19:20:30+01:00',
            Fingerprint::input(Fingerprint::ALTA_FIELDS, $values),
        );
        $this->assertSame('3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60', Fingerprint::alta($values));
    }

    public function test_official_example_2_chained_record(): void
    {
        $this->assertSame('F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97', Fingerprint::alta([
            'IDEmisorFactura' => '89890001K', 'NumSerieFactura' => '12345679/G34', 'FechaExpedicionFactura' => '01-01-2024',
            'TipoFactura' => 'F1', 'CuotaTotal' => '12.35', 'ImporteTotal' => '123.45',
            'Huella' => '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60', 'FechaHoraHusoGenRegistro' => '2024-01-01T19:20:35+01:00',
        ]));
    }

    public function test_official_example_3_annulment(): void
    {
        $this->assertSame('177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68', Fingerprint::anulacion([
            'IDEmisorFacturaAnulada' => '89890001K', 'NumSerieFacturaAnulada' => '12345679/G34', 'FechaExpedicionFacturaAnulada' => '01-01-2024',
            'Huella' => 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97', 'FechaHoraHusoGenRegistro' => '2024-01-01T19:20:40+01:00',
        ]));
    }

    public function test_values_are_trimmed(): void
    {
        // «se obtendrá el valor “12345678 / G33”» (apartado 3).
        $this->assertSame('NumSerieFactura=12345678 / G33', Fingerprint::input(['NumSerieFactura'], ['NumSerieFactura' => ' 12345678 / G33 ']));
    }
}
