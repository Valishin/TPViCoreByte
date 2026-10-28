<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use Filament\Resources\Pages\ViewRecord;

/** Sin acciones: un ticket no se edita ni se borra. */
class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function getTitle(): string
    {
        return "Ticket {$this->record->fullNumber()}";
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
