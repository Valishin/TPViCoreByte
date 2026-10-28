<?php

namespace App\Events\Pos;

use App\Models\Printer;

class PrinterStatusChanged extends PosEvent
{
    public int $printerId;

    public string $name;

    public string $status;

    public ?string $error;

    public function __construct(Printer $printer)
    {
        $this->printerId = $printer->id;
        $this->name = $printer->name;
        $this->status = $printer->status->value;
        $this->error = $printer->last_error;
    }

    public function broadcastAs(): string
    {
        return 'printer.status';
    }
}
