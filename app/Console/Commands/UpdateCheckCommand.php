<?php

namespace App\Console\Commands;

use App\Enums\CashSessionStatus;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\CashSession;
use App\Models\Order;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ¿Se puede actualizar ahora? No con la caja abierta, con pedidos abiertos ni
 * con domicilios pendientes de liquidar. Lo usa tpv-update antes de tocar nada.
 */
#[Signature('tpv:update-check')]
#[Description('Comprueba que no hay caja abierta, pedidos abiertos ni domicilios sin liquidar')]
class UpdateCheckCommand extends Command
{
    public function handle(): int
    {
        $reasons = [];
        if (CashSession::query()->where('status', CashSessionStatus::Open)->exists()) {
            $reasons[] = 'La caja está abierta: haz el cierre Z.';
        }
        if ($open = Order::query()->where('status', OrderStatus::Open)->count()) {
            $reasons[] = "Hay {$open} pedido(s) abierto(s).";
        }
        $unsettled = Order::query()->where('type', OrderType::Delivery)
            ->whereIn('delivery_status', [DeliveryStatus::OutForDelivery, DeliveryStatus::Delivered])
            ->whereNull('courier_settlement_id')->count();
        if ($unsettled) {
            $reasons[] = "Hay {$unsettled} domicilio(s) pendiente(s) de liquidar.";
        }

        if ($reasons) {
            foreach ($reasons as $reason) {
                $this->error($reason);
            }

            return self::FAILURE;
        }
        $this->info('Se puede actualizar: caja cerrada, sin pedidos abiertos ni domicilios pendientes.');

        return self::SUCCESS;
    }
}
