<?php

namespace App\Filament\Resources\TicketSeries\Pages;

use App\Filament\Resources\TicketSeries\SeriesNumbering;
use App\Filament\Resources\TicketSeries\TicketSeriesResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTicketSeries extends EditRecord
{
    protected static string $resource = TicketSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SeriesNumbering::action(),
            DeleteAction::make(),
        ];
    }
}
