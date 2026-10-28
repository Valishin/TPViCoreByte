<?php

namespace App\Events\Pos;

use App\Models\Table;

class TableStatusChanged extends PosEvent
{
    public int $tableId;

    public int $roomId;

    public string $status;

    public function __construct(Table $table)
    {
        $this->tableId = $table->id;
        $this->roomId = $table->room_id;
        $this->status = $table->status->value;
    }

    public function broadcastAs(): string
    {
        return 'table.status';
    }
}
