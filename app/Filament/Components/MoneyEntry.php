<?php

namespace App\Filament\Components;

use App\Support\Format;
use Filament\Infolists\Components\TextEntry;

/** Importe en céntimos mostrado como "12,50 €" en vistas de detalle. */
class MoneyEntry extends TextEntry
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->formatStateUsing(fn ($state) => Format::money($state === null ? null : (int) $state));
    }
}
