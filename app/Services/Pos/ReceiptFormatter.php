<?php

namespace App\Services\Pos;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\TicketType;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Services\Printing\Ticket as Printable;
use App\Support\Format;

/**
 * Impresión de cara al cliente y a la caja: ticket (factura simplificada o
 * rectificativa), justificante de pago e informes X / Z. Se adapta al ancho
 * del papel: 48 columnas (80 mm) o 32 (58 mm).
 */
class ReceiptFormatter
{
    /** Ticket. Con $copy, marca "COPIA" arriba y abajo. */
    public function ticket(Ticket $ticket, int $columns = 48, bool $copy = false): Printable
    {
        $ticket->loadMissing(['lines', 'payments', 'issuedBy', 'order.table.room', 'rectifiedTicket']);
        $issuer = $ticket->issuer ?? [];
        $p = new Printable;

        if ($copy) {
            $p->line('*** COPIA ***', Printable::ALIGN_CENTER, bold: true, reverse: true);
        }

        $p->title($issuer['trade_name'] ?? '')
            ->line($issuer['legal_name'] ?? '', Printable::ALIGN_CENTER)
            ->line('NIF '.($issuer['tax_id'] ?? ''), Printable::ALIGN_CENTER);
        foreach (array_filter([$issuer['address'] ?? null, trim(($issuer['postal_code'] ?? '').' '.($issuer['city'] ?? '')), isset($issuer['phone']) ? 'Tel. '.$issuer['phone'] : null]) as $text) {
            $p->line($text, Printable::ALIGN_CENTER);
        }
        $p->separator('=');

        if ($ticket->type === TicketType::Rectifying) {
            // VeriFactu: las rectificativas de facturas simplificadas son del tipo R5.
            $p->line('FACTURA RECTIFICATIVA', Printable::ALIGN_CENTER, bold: true)
                ->line('Rectifica a '.$ticket->rectifiedTicket?->fullNumber().' ('.$ticket->rectification_method?->label().')', Printable::ALIGN_CENTER);
            if ($ticket->rectification_reason) {
                $p->line('Motivo: '.$ticket->rectification_reason);
            }
        } else {
            $p->line('FACTURA SIMPLIFICADA', Printable::ALIGN_CENTER, bold: true);
        }

        $p->columns('Nº '.$ticket->fullNumber(), $ticket->issued_at->format('d/m/Y H:i'), bold: true)
            ->columns($this->where($ticket), $ticket->issuedBy ? 'Atendió: '.$ticket->issuedBy->name : '')
            ->separator();

        // Líneas
        $lines = $ticket->lines;
        foreach ($lines->whereNull('parent_line_id') as $line) {
            $p->columns($line->quantity.' '.$this->lineName($line), Format::money($line->line_total));
            foreach ($line->modifiers ?? [] as $m) {
                $p->line('   + '.($m['quantity'] > 1 ? $m['quantity'].'x ' : '').$m['name']);
            }
            foreach ($lines->where('parent_line_id', $line->id) as $child) {
                $p->line('   · '.$this->lineName($child));
                foreach ($child->modifiers ?? [] as $m) {
                    $p->line('     + '.$m['name']);
                }
            }
            if ($line->is_invitation) {
                $p->columns('   Invitación', '-'.Format::money($line->discount_amount));
            } elseif ($line->discount_amount) {
                $p->columns('   Descuento', '-'.Format::money($line->discount_amount));
            }
        }
        $p->separator();

        if ($ticket->lines_discount || $ticket->discount_amount || $ticket->delivery_fee) {
            $p->columns('Suma', Format::money($ticket->subtotal - $ticket->lines_discount));
            if ($ticket->discount_amount) {
                $p->columns('Descuento'.($ticket->discount_reason ? " ({$ticket->discount_reason})" : ''), '-'.Format::money($ticket->discount_amount));
            }
            if ($ticket->delivery_fee) {
                $p->columns('Gastos de envío', Format::money($ticket->delivery_fee));
            }
        }
        $p->line('TOTAL  '.Format::money($ticket->total), Printable::ALIGN_RIGHT, bold: true, large: true)
            ->line('IVA incluido', Printable::ALIGN_RIGHT);

        // Desglose de IVA
        $p->separator()->line($this->vatRow('IVA', 'Base', 'Cuota', 'Total', $columns), bold: true);
        foreach ($ticket->vat_breakdown as $row) {
            $p->line($this->vatRow(Format::percent($row['rate']), Format::money($row['base']), Format::money($row['tax']), Format::money($row['total']), $columns));
        }

        // Pagos
        if ($ticket->payments->isNotEmpty()) {
            $p->separator();
            foreach ($ticket->payments as $payment) {
                $this->payment($p, $payment);
            }
        }

        $this->verifactuBlock($p, $ticket);

        $p->feed();
        foreach (preg_split('/\R/', (string) Setting::current()->ticket_footer) as $footer) {
            if ($footer !== '') {
                $p->line($footer, Printable::ALIGN_CENTER);
            }
        }
        if ($copy) {
            $p->line('*** COPIA ***', Printable::ALIGN_CENTER, bold: true, reverse: true);
        }

        return $p->feed(3)->cut();
    }

    /** Justificante de una parte del pago: NO es una factura. */
    public function paymentReceipt(Ticket $ticket, Payment $payment, int $part, int $parts): Printable
    {
        $p = (new Printable)
            ->title($ticket->issuer['trade_name'] ?? '')
            ->line('JUSTIFICANTE DE PAGO', Printable::ALIGN_CENTER, bold: true, reverse: true)
            ->line('NO ES UNA FACTURA', Printable::ALIGN_CENTER, bold: true)
            ->separator()
            ->line('Corresponde al ticket '.$ticket->fullNumber(), Printable::ALIGN_CENTER)
            ->line("Parte {$part} de {$parts}", Printable::ALIGN_CENTER, bold: true)
            ->line($payment->paid_at->format('d/m/Y H:i'), Printable::ALIGN_CENTER)
            ->separator();
        $this->payment($p, $payment);

        return $p->columns('Total del ticket', Format::money($ticket->total))
            ->feed()
            ->line('Pida la factura simplificada en caja', Printable::ALIGN_CENTER)
            ->feed(3)->cut();
    }

    /** Informe X (parcial) o Z (cierre). */
    public function cashReport(array $report, bool $final, int $columns = 48): Printable
    {
        $p = (new Printable)
            ->title(Setting::current()->trade_name)
            ->line($final ? 'INFORME Z · CIERRE DE CAJA' : 'INFORME X · PARCIAL', Printable::ALIGN_CENTER, bold: true, reverse: true);
        if ($final && $report['z_number']) {
            $p->line('Z nº '.$report['z_number'], Printable::ALIGN_CENTER, bold: true, large: true);
        }
        $p->columns('Apertura', $report['opened_at'])
            ->columns($final ? 'Cierre' : 'Hora', $report['generated_at'])
            ->separator('=');

        $p->line('VENTAS', bold: true)
            ->columns('Tickets ('.$report['tickets']['count'].')', Format::money($report['tickets']['total']))
            ->columns('Rectificativos ('.$report['rectifications']['count'].')', Format::money($report['rectifications']['total']))
            ->columns('TOTAL VENTAS', Format::money($report['sales_total']), bold: true)
            ->columns('Descuentos', Format::money($report['discounts']['amount']))
            ->columns('Invitaciones ('.$report['invitations']['count'].')', Format::money($report['invitations']['amount']))
            ->separator();

        $p->line('FORMAS DE PAGO', bold: true);
        foreach ($report['by_method'] as $row) {
            $p->columns($row['label'].' ('.$row['count'].')', Format::money($row['amount']));
        }
        if ($report['tips']['total']) {
            $p->separator()->line('PROPINAS (fuera de ventas)', bold: true);
            foreach ($report['tips']['by_method'] as $row) {
                $p->columns($row['label'], Format::money($row['amount']));
            }
            foreach ($report['tips']['by_waiter'] as $row) {
                $p->columns('  '.$row['name'], Format::money($row['amount']));
            }
        }

        $p->separator()->line('IVA', bold: true)->line($this->vatRow('Tipo', 'Base', 'Cuota', 'Total', $columns), bold: true);
        foreach ($report['by_vat'] as $row) {
            $p->line($this->vatRow(Format::percent($row['rate']), Format::money($row['base']), Format::money($row['tax']), Format::money($row['total']), $columns));
        }

        $p->separator()->line('POR CAMARERO', bold: true);
        foreach ($report['by_waiter'] as $row) {
            $p->columns($row['name'].' ('.$row['count'].')', Format::money($row['total']));
        }
        $p->separator()->line('POR TIPO DE PEDIDO', bold: true);
        foreach ($report['by_order_type'] as $row) {
            $p->columns($row['label'].' ('.$row['count'].')', Format::money($row['total']));
        }

        $p->separator('=')->line('EFECTIVO', bold: true)
            ->columns('Fondo inicial', Format::money($report['cash']['opening_float']))
            ->columns('Cobros en efectivo', Format::money($report['cash']['cash_in']))
            ->columns('Efectivo esperado', Format::money($report['cash']['expected']), bold: true);
        if ($final) {
            $p->columns('Contado', Format::money($report['cash']['counted']))
                ->columns('Diferencia', Format::money($report['cash']['difference']), bold: true);
            if ($report['notes'] ?? null) {
                $p->line('Notas: '.$report['notes']);
            }
        }

        return $p->feed(3)->cut();
    }

    private function payment(Printable $p, Payment $payment): void
    {
        $p->columns($payment->method->label(), Format::money($payment->amount), bold: true);
        if ($payment->method === PaymentMethod::Cash && $payment->tendered !== null) {
            $p->columns('  Entregado', Format::money($payment->tendered))
                ->columns('  Cambio', Format::money($payment->change));
        }
        if ($payment->tip) {
            $p->columns('  Propina (no incluida)', Format::money($payment->tip));
        }
    }

    /**
     * Hueco reservado para VeriFactu: código QR de cotejo y la leyenda
     * "Factura verificable en la sede electrónica de la AEAT" / "VERI*FACTU".
     * Se implementará en la fase de VeriFactu (datos en fiscal_records).
     */
    private function verifactuBlock(Printable $p, Ticket $ticket): void
    {
        // Pendiente: $p->qr(...) y leyenda. De momento no se imprime nada.
    }

    private function lineName(TicketLine $line): string
    {
        $half = $line->kind->value === 'mitad' ? '½ ' : '';

        return $half.$line->name.($line->variant_name ? " ({$line->variant_name})" : '');
    }

    private function where(Ticket $ticket): string
    {
        $table = $ticket->order?->table;

        return $table ? "Mesa {$table->name}" : ($ticket->order_type?->label() ?? OrderType::Bar->label());
    }

    /** Fila de 4 columnas (IVA · base · cuota · total) ajustada al ancho. */
    private function vatRow(string $a, string $b, string $c, string $d, int $columns): string
    {
        $first = $columns >= 48 ? 9 : 6;
        $rest = intdiv($columns - $first, 3);
        $clip = fn (string $s, int $w) => mb_substr($s, 0, $w);

        return mb_str_pad($clip($a, $first), $first)
            .mb_str_pad($clip($b, $rest), $rest, ' ', STR_PAD_LEFT)
            .mb_str_pad($clip($c, $rest), $rest, ' ', STR_PAD_LEFT)
            .mb_str_pad($clip($d, $rest), $rest, ' ', STR_PAD_LEFT);
    }
}
