<?php

namespace Tests\Unit;

use App\Enums\TaxIdType;
use App\Support\TaxId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaxIdTest extends TestCase
{
    public static function valid(): array
    {
        return [
            'NIF' => ['12345678Z', TaxIdType::Nif],
            'NIF con ceros' => ['00000000T', TaxIdType::Nif],
            'NIF con espacios y guion' => [' 12.345.678-z ', TaxIdType::Nif],
            'NIE X' => ['X1234567L', TaxIdType::Nie],
            'NIE Y' => ['Y1234567X', TaxIdType::Nie],
            'NIE Z' => ['Z1234567R', TaxIdType::Nie],
            'CIF sociedad anónima (dígito)' => ['A28015865', TaxIdType::Cif],
            'CIF sociedad limitada (dígito)' => ['B12345674', TaxIdType::Cif],
            'CIF organismo público (letra)' => ['Q2826000H', TaxIdType::Cif],
            'NIF-IVA Alemania' => ['DE123456789', TaxIdType::VatEu],
            'NIF-IVA Francia' => ['FR12345678901', TaxIdType::VatEu],
            'NIF-IVA Portugal' => ['PT123456789', TaxIdType::VatEu],
            'NIF-IVA España (CIF válido dentro)' => ['ESB12345674', TaxIdType::VatEu],
        ];
    }

    public static function invalid(): array
    {
        return [
            'NIF letra mal' => ['12345678A'],
            'NIF corto' => ['1234567Z'],
            'NIE letra mal' => ['X1234567A'],
            'CIF control mal' => ['B12345678'],
            'CIF Q con dígito (debe ser letra)' => ['Q28260008'],
            'CIF A con letra (debe ser dígito)' => ['A2801586E'],
            'NIF-IVA Italia corto' => ['IT1234567890'],
            'NIF-IVA España con CIF mal' => ['ESB12345678'],
            'país fuera de la UE' => ['US123456789'],
            'vacío' => [''],
        ];
    }

    #[DataProvider('valid')]
    public function test_valid_ids(string $value, TaxIdType $type): void
    {
        $this->assertSame($type, TaxId::detect($value));
        $this->assertTrue(TaxId::isValid($value), $value);
        $this->assertNull(TaxId::error($value));
    }

    #[DataProvider('invalid')]
    public function test_invalid_ids(string $value): void
    {
        $this->assertFalse(TaxId::isValid($value), $value);
        $this->assertNotNull(TaxId::error($value));
    }

    public function test_normalize_and_other_documents(): void
    {
        $this->assertSame('12345678Z', TaxId::normalize(' 12345678-z '));
        $this->assertNull(TaxId::normalize('  '));
        $this->assertTrue(TaxId::isValid('AB1234567', TaxIdType::Other));     // pasaporte
        $this->assertFalse(TaxId::isValid('AB1', TaxIdType::Other));
        $this->assertSame('El CIF no es válido: revisa el dígito o la letra de control.', TaxId::error('B12345678'));
    }
}
