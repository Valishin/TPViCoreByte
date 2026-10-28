<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Ticket;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodaySalesStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $today = Ticket::query()->whereDate('issued_at', today());
        $yesterday = (int) Ticket::query()->whereDate('issued_at', today()->subDay())->sum('total');
        $total = (int) (clone $today)->sum('total');
        $count = (clone $today)->count();
        $pending = (int) (clone $today)->where('payment_status', PaymentStatus::Pending)->sum('total');

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
