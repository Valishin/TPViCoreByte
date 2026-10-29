<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Acciones que un camarero solo puede hacer con la autorización (PIN) de un encargado. */
enum AuthorizationAction: string implements HasLabel
{
    case Discount = 'descuento';
    case Invitation = 'invitacion';
    case VoidTicket = 'anular_ticket';
    case OpenDrawer = 'abrir_cajon';
    case Reprint = 'reimprimir';
    case OpenCash = 'abrir_caja';
    case CloseCash = 'cerrar_caja';
    case MinimumOrder = 'pedido_minimo';
    case CancelOrder = 'cancelar_pedido';
    case FailedDelivery = 'no_entregado';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'Descuento por encima del límite',
            self::Invitation => 'Invitación',
            self::VoidTicket => 'Anular ticket',
            self::OpenDrawer => 'Abrir el cajón sin venta',
            self::Reprint => 'Reimprimir otra copia',
            self::OpenCash => 'Abrir caja',
            self::CloseCash => 'Cerrar caja',
            self::MinimumOrder => 'Enviar sin llegar al pedido mínimo',
            self::CancelOrder => 'Cancelar un pedido ya enviado a cocina',
            self::FailedDelivery => 'Pedido no entregado (rectificativo)',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
