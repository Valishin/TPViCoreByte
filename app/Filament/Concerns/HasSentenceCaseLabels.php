<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Str;

/**
 * En español los títulos van en mayúscula solo la primera letra
 * ("Cierres de caja", no "Cierres De Caja").
 */
trait HasSentenceCaseLabels
{
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
