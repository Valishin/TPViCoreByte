<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Ticket;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodaySalesStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static ?bool $isLazyLoaded = false;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        // Sin anulados ni canjes (no son ventas), como el informe de caja.
        $today = Ticket::query()->countsAsSale()->whereDate('issued_at', today());
        $yesterday = (int) Ticket::query()->countsAsSale()->whereDate('issued_at', today()->subDay())->sum('total');
        $total = (int) (clone $today)->sum('total');
        $count = (clone $today)->count();
        $pending = (int) (clone $today)->pendingCollection()->sum('total');

        $open = Order::query()->where('status', OrderStatus::Open)
            ->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $openText = collect(OrderType::cases())
            ->filter(fn (OrderType $t) => $open->has($t->value))
            ->map(fn (OrderType $t) => $t->label().': '.$open[$t->value])
            ->implode(' · ');

        return [
            Stat::make('Ventas de hoy', Format::money($total))
                ->description('Ayer: '.Format::money($yesterday))
                ->descriptionIcon($total >= $yesterday ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($total >= $yesterday ? 'success' : 'gray'),
            Stat::make('Tickets de hoy', (string) $count)
                ->description($count ? 'Ticket medio: '.Format::money(intdiv($total, $count)) : 'Aún no hay tickets')
                ->descriptionIcon('heroicon-m-receipt-percent'),
            Stat::make('Pedidos abiertos', (string) $open->sum())
                ->description($openText ?: 'Ninguno')
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color($open->sum() ? 'warning' : 'gray'),
            Stat::make('Pendiente de cobro hoy', Format::money($pending))
                ->description('Domicilios que se cobran al entregar')
                ->descriptionIcon('heroicon-m-truck')
                ->color($pending ? 'warning' : 'gray'),
        ];
    }
}
