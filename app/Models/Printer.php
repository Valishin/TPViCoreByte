<?php

namespace App\Models;

use App\Enums\PrinterType;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Unguarded]
class Printer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => PrinterType::class,
            'is_active' => 'boolean',
            'port' => 'integer',
            'columns' => 'integer',
            'codepage' => 'integer',
        ];
    }
}
