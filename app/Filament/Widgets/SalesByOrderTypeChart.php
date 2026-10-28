<?php

namespace App\Filament\Widgets;

use App\Enums\OrderType;
use App\Models\Setting;
use App\Models\Ticket;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class SalesByOrderTypeChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static ?bool $isLazyLoaded = false;

    protected ?string $heading = 'Ventas por tipo de pedido · últimos 7 días';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    private const COLORS = [
        'mesa' => '#f97316',
        'barra' => '#0ea5e9',
        'para_llevar' => '#a855f7',
        'domicilio' => '#16a34a',
    ];

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = collect(range(6, 0))->map(fn (int $i) => today()->subDays($i));

        $rows = Ticket::query()
            ->where('issued_at', '>=', $days->first()->copy()->startOfDay())
            ->selectRaw('date(issued_at) as day, order_type, sum(total) as total')
            ->groupBy('day', 'order_type')
            ->get();

        $types = Setting::query()->first()?->enabled_order_types ?? collect(OrderType::cases());

        return [
            'labels' => $days->map(fn (Carbon $d) => ucfirst($d->locale('es')->isoFormat('ddd D')))->all(),
            'datasets' => $types->map(fn (OrderType $type) => [
                'label' => $type->label(),
                'backgroundColor' => self::COLORS[$type->value],
                'borderRadius' => 4,
                // Euros solo para dibujar el gráfico (los importes siguen en céntimos en la BD).
                'data' => $days->map(fn (Carbon $d) => (int) $rows
                    ->where('order_type', $type)
                    ->filter(fn ($r) => Carbon::parse($r->day)->isSameDay($d))
                    ->sum('total') / 100)->all(),
            ])->values()->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, ticks: { callback: (v) => v.toLocaleString('es-ES') + ' €' } },
                },
                plugins: {
                    tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + c.parsed.y.toLocaleString('es-ES', { minimumFractionDigits: 2 }) + ' €' } },
                },
            }
        JS);
    }
}
