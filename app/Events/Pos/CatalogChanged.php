<?php

namespace App\Events\Pos;

class CatalogChanged extends PosEvent
{
    public function __construct(public string $version) {}

    public function broadcastAs(): string
    {
        return 'catalog.changed';
    }
}
