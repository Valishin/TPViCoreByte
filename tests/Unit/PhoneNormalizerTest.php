<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public static function phones(): array
    {
        return [
            'móvil con espacios' => ['612 34 56 78', '+34612345678'],
            'con +34 y guiones' => ['+34 612-345-678', '+34612345678'],
            'con 0034' => ['0034612345678', '+34612345678'],
            'con 34 sin +' => ['34612345678', '+34612345678'],
            'fijo con puntos y paréntesis' => ['(91) 123.45.67', '+34911234567'],
            'extranjero' => ['+44 20 7946 0958', '+442079460958'],
            'vacío' => ['  ', null],
        ];
    }

    #[DataProvider('phones')]
    public function test_normalizes_phone(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::normalize($input));
    }
}
