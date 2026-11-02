<?php

namespace App\Services\Pos;

use App\Enums\OrderLineKind;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Table;
use App\Models\User;
use App\Services\Printing\Ticket;
use Illuminate\Support\Collection;

/**
 * Formatos de impresión del servicio: comanda y anulación (la cuenta, en ReceiptFormatter).
 * Pensados para leerse de un vistazo en cocina: lo importante, en grande.
 */
class KitchenFormatter
{
    /**
     * Comanda para UNA impresora.
     *
     * @param  Collection<int, OrderLine>  $lines  líneas de esa impresora (raíces sin hijas o hijas de menús / mitades)
     */
    public function kitchen(Order $order, Collection $lines, int $sendNumber, ?User $by, ?string $note = null): Ticket
    {
        $ticket = new Ticket;
        if ($order->isTakeawayOrDelivery()) {
            $this->takeawayHeader($ticket, $order);
        } else {
            $ticket->line($this->where($order), Ticket::ALIGN_CENTER, bold: true, large: true);
        }
        $ticket->line("Envío {$sendNumber} · ".now()->format('H:i').($by ? " · {$by->name}" : ''), Ticket::ALIGN_CENTER, bold: true);

        if ($order->guests) {
            $ticket->line("{$order->guests} comensales", Ticket::ALIGN_CENTER);
        }
        if ($note) {
            $ticket->line($note, Ticket::ALIGN_CENTER, bold: true, reverse: true);
        }
        $ticket->separator('=');

        foreach ($lines as $line) {
            $this->item($ticket, $line);
        }

        return $ticket->separator('=')->feed(3)->cut();
    }

    /** ANULACIÓN de una línea ya enviada (muy visible). */
    public function void(Order $order, Collection $lines, string $reason, ?User $by): Ticket
    {
        $ticket = (new Ticket)
            ->line('*** ANULACIÓN ***', Ticket::ALIGN_CENTER, bold: true, large: true, reverse: true)
            ->line($this->where($order).($order->customer_name ? " · {$order->customer_name}" : ''), Ticket::ALIGN_CENTER, bold: true, large: true)
            ->line(now()->format('H:i').($by ? " · {$by->name}" : ''), Ticket::ALIGN_CENTER)
            ->separator('=');

        foreach ($lines as $line) {
            $this->item($ticket, $line, prefix: 'NO HACER: ');
        }

        return $ticket
            ->separator('=')
            ->line("Motivo: {$reason}", bold: true)
            ->line('*** ANULACIÓN ***', Ticket::ALIGN_CENTER, bold: true, large: true, reverse: true)
            ->feed(3)->cut();
    }

    private function item(Ticket $ticket, OrderLine $line, string $prefix = ''): void
    {
        $parent = $line->parent;
        $quantity = $line->quantity * ($parent?->quantity ?? 1);
        $half = $line->kind === OrderLineKind::Half ? '½ ' : '';

        $ticket->line("{$prefix}{$quantity}x {$half}".($line->product?->short_name ?: $this->name($line, withVariant: false)), bold: true, large: true);

        if ($line->variant_name) {
            $ticket->line("   ({$line->variant_name})", bold: true);
        }
        foreach ($line->modifiers as $modifier) {
            $ticket->line('   > '.($modifier->quantity > 1 ? "{$modifier->quantity}x " : '').$modifier->name, bold: true);
        }
        if ($line->notes) {
            $ticket->line("   !! {$line->notes}", bold: true, reverse: true);
        }
        if ($parent) {
            $ticket->line($line->kind === OrderLineKind::Half
                ? '   ↳ '.$this->otherHalves($line)
                : "   ↳ del {$parent->name}");
        }
        $ticket->separator('-');
    }

    private function otherHalves(OrderLine $half): string
    {
        $others = $half->parent->children->where('id', '!=', $half->id)->pluck('name')->implode(', ');

        return "mitad y mitad con {$others}";
    }

    private function name(OrderLine $line, bool $withVariant = true): string
    {
        $isHalfParent = str_starts_with($line->name, 'Mitad y mitad');

        return $line->name.($withVariant && $line->variant_name && ! $isHalfParent ? " ({$line->variant_name})" : '')
            .($isHalfParent && $line->variant_name ? " ({$line->variant_name})" : '');
    }

    /** DOMICILIO / LLEVAR muy visible, cliente y hora a la que debe estar listo. */
    private function takeawayHeader(Ticket $ticket, Order $order): void
    {
        $ticket->line(mb_strtoupper($order->type === OrderType::Delivery ? 'Domicilio' : 'Llevar'), Ticket::ALIGN_CENTER, bold: true, large: true, reverse: true);
        if ($order->customer_name) {
            $ticket->line($order->customer_name, Ticket::ALIGN_CENTER, bold: true, large: true);
        }
        if ($ready = $order->readyBy()) {
            $ticket->line('LISTO A LAS '.$ready->format('H:i'), Ticket::ALIGN_CENTER, bold: true, large: true);
            if ($order->asap) {
                $ticket->line('Lo antes posible', Ticket::ALIGN_CENTER, bold: true);
            }
        }
    }

    /** «MESA 4 UNIDA A MESA 3» para cocina y barra (opcional, Ajustes). */
    public function joinNotice(Order $order, Table $joined, ?User $by): Ticket
    {
        $order->loadMissing('table');

        return (new Ticket)
            ->line('*** AVISO ***', Ticket::ALIGN_CENTER, bold: true, reverse: true)
            ->line(mb_strtoupper("Mesa {$joined->name} unida a mesa {$order->table->name}"), Ticket::ALIGN_CENTER, bold: true, large: true)
            ->line('Ahora: '.mb_strtoupper((string) $order->tableLabel()), Ticket::ALIGN_CENTER, bold: true)
            ->line(now()->format('H:i').($by ? " · {$by->name}" : ''), Ticket::ALIGN_CENTER)
            ->feed(3)->cut();
    }

    private function where(Order $order): string
    {
        $order->loadMissing('table.room');

        return $order->table
            ? mb_strtoupper((string) $order->tableLabel()).($order->table->room ? " · {$order->table->room->name}" : '')
            : mb_strtoupper($order->type->label());
    }
}
