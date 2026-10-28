<?php

namespace App\Events\Pos;

use App\Models\PrintJob;

/** Cambia el estado de un envío (impreso / fallido): las pantallas actualizan sus avisos. */
class PrintJobUpdated extends PosEvent
{
    public int $printJobId;

    public ?int $orderId;

    public string $status;

    public function __construct(PrintJob $job)
    {
        $this->printJobId = $job->id;
        $this->orderId = $job->order_id;
        $this->status = $job->status->value;
    }

    public function broadcastAs(): string
    {
        return 'print-job.updated';
    }
}
