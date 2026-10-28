<?php

namespace App\Filament\Components;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Filtro "desde / hasta" sobre una columna de fecha. */
class DateRangeFilter
{
    public static function make(string $column, string $label = 'Fecha'): Filter
    {
        return Filter::make($column)
            ->label($label)
            ->schema([
                DatePicker::make('from')->label("{$label} desde")->native(false)->displayFormat('d/m/Y'),
                DatePicker::make('until')->label("{$label} hasta")->native(false)->displayFormat('d/m/Y'),
            ])
            ->columns(2)
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn ($q, $date) => $q->where($column, '>=', Carbon::parse($date)->startOfDay()))
                ->when($data['until'] ?? null, fn ($q, $date) => $q->where($column, '<=', Carbon::parse($date)->endOfDay())))
            ->indicateUsing(function (array $data) use ($label) {
                $indicators = [];
                if ($data['from'] ?? null) {
                    $indicators[] = "{$label} desde ".Carbon::parse($data['from'])->format('d/m/Y');
                }
                if ($data['until'] ?? null) {
                    $indicators[] = "{$label} hasta ".Carbon::parse($data['until'])->format('d/m/Y');
                }

                return $indicators;
            });
    }
}
