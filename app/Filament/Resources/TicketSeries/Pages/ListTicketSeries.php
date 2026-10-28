<?php

namespace App\Filament\Resources\TicketSeries\Pages;

use App\Filament\Resources\TicketSeries\TicketSeriesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTicketSeries extends ListRecords
{
    protected static string $resource = TicketSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
