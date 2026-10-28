<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Crea los ajustes del local (una fila) con sus series de tickets. */
    protected function createSettings(array $attributes = []): Setting
    {
        return Setting::factory()->create($attributes);
    }
}
