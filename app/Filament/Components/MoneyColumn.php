<?php

namespace App\Filament\Components;

use App\Support\Format;
use Filament\Tables\Columns\TextColumn;

/** Columna de importe en céntimos mostrada como "12,50 €". */
class MoneyColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->formatStateUsing(fn ($state) => Format::money($state === null ? null : (int) $state))
            ->alignEnd();
    }
}
