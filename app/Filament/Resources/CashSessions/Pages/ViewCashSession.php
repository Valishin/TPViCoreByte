<?php

namespace App\Filament\Resources\CashSessions\Pages;

use App\Filament\Resources\CashSessions\CashSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCashSession extends ViewRecord
{
    protected static string $resource = CashSessionResource::class;

    public function getTitle(): string
    {
        return $this->record->z_number ? "Cierre Z nº {$this->record->z_number}" : 'Caja abierta';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
