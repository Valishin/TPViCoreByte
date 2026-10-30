<?php

namespace Tests;

use App\Models\Setting;
use App\Support\DocumentNumber;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Crea los ajustes del local (una fila) con sus series de tickets. */
    protected function createSettings(array $attributes = []): Setting
    {
        return Setting::factory()->create($attributes);
    }

    /** Número completo esperado en el año actual (T-26-000001). */
    protected function docNumber(string $series, int $number, int $digits = DocumentNumber::DEFAULT_DIGITS): string
    {
        return DocumentNumber::format($series, DocumentNumber::yearOf(now()), $number, $digits);
    }
}
