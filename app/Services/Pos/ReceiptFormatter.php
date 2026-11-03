<?php

namespace App\Services\Pos;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TicketType;
use App\Models\CourierSettlement;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Models\User;
use App\Services\Billing\InvoiceDocument;
use App\Services\Printing\Ticket as Printable;
use App\Services\Verifactu\TaxQr;
use App\Support\Format;
use App\Support\MapsLink;
use App\Support\PostalCode;

/**
 * Impresión de cara al cliente y a la caja: ticket (factura simplificada o
 * rectificativa), cuenta (con el mismo formato), justificante de pago e
 * informes X / Z. Se adapta al ancho
 * del papel: 48 columnas (80 mm) o 32 (58 mm).
 */
class ReceiptFormatter
{
    /** Interlineado de la parte de reparto (puntos de 1/203"; el normal son ~30). */
    private const DELIVERY_LINE_SPACING = 60;

    /** Ticket (o factura completa, ver invoice()). Con $copy, marca "COPIA" arriba y abajo. */
    public function ticket(Ticket $ticket, int $columns = 48, bool $copy = false): Printable
    {
        if ($ticket->type->isFullInvoice()) {
            return $this->invoice($ticket, $columns, $copy);
        }
        $ticket->loadMissing(['lines', 'payments', 'issuedBy', 'order.table.room', 'rectifiedTicket']);
        $p = new Printable;

        if ($copy) {
            $p->line('*** COPIA ***', Printable::ALIGN_CENTER, bold: true, reverse: true);
        }

        $this->taxQrBlock($p, $ticket);
        $this->issuerHeader($p, $ticket->issuer ?? []);

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
            $this->halfSurcharge($p, $line->half_and_half_surcharge);
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

        $this->vatBlock($p, $ticket->vat_breakdown, $columns);

        // Pagos
        if ($ticket->payments->isNotEmpty()) {
            $p->separator();
            foreach ($ticket->payments as $payment) {
                $this->payment($p, $payment);
            }
        }

        $this->footer($p->feed());
        if ($copy) {
            $p->line('*** COPIA ***', Printable::ALIGN_CENTER, bold: true, reverse: true);
        }

        return $p->feed(3)->cut();
    }

    /**
     * Cuenta (precuenta) que se lleva a la mesa: los mismos datos que el ticket
     * de cobro (emisor, líneas, descuentos, total, desglose de IVA y pie), pero
     * sin número y marcada como NO VÁLIDA COMO FACTURA.
     *
     * @param  list<array{rate: int, base: int, tax: int, total: int}>  $vatBreakdown
     */
    public function precheck(Order $order, array $vatBreakdown, ?User $by = null, int $columns = 48): Printable
    {
        $order->loadMissing(['lines.modifiers', 'table.room']);
        $p = new Printable;
        $this->issuerHeader($p, Setting::current()->issuerSnapshot());

        $where = $order->table ? (string) $order->tableLabel() : $order->type->label();
        $p->line('CUENTA', Printable::ALIGN_CENTER, bold: true, large: true)
            ->line('NO VÁLIDO COMO FACTURA', Printable::ALIGN_CENTER, bold: true)
            ->columns($where.($order->guests ? " · {$order->guests} pax" : ''), now()->format('d/m/Y H:i'), bold: true);
        if ($by) {
            $p->line('Atendió: '.$by->name);
        }
        $p->separator();

        $lines = $order->lines->whereNull('voided_at');
        foreach ($lines->whereNull('parent_line_id') as $line) {
            $p->columns($line->quantity.' '.$this->orderLineName($line), Format::money($line->line_total));
            foreach ($line->modifiers as $m) {
                $p->line('   + '.($m->quantity > 1 ? $m->quantity.'x ' : '').$m->name);
            }
            foreach ($lines->where('parent_line_id', $line->id) as $child) {
                $p->line('   · '.$this->orderLineName($child));
                foreach ($child->modifiers as $m) {
                    $p->line('     + '.$m->name);
                }
            }
            $this->halfSurcharge($p, $line->half_and_half_surcharge);
            if ($line->is_invitation) {
                $p->columns('   Invitación', '-'.Format::money($line->discount_amount));
            } elseif ($line->discount_amount) {
                $p->columns('   Descuento', '-'.Format::money($line->discount_amount));
            }
        }
        $p->separator();

        $deliveryFee = (int) $order->delivery_fee;
        if ($order->discount_amount || $deliveryFee) {
            $p->columns('Suma', Format::money($order->subtotal));
            if ($order->discount_amount) {
                $p->columns('Descuento'.($order->discount_reason ? " ({$order->discount_reason})" : ''), '-'.Format::money($order->discount_amount));
            }
            if ($deliveryFee) {
                $p->columns('Gastos de envío', Format::money($deliveryFee));
            }
        }
        $p->line('TOTAL  '.Format::money($order->total), Printable::ALIGN_RIGHT, bold: true, large: true)
            ->line('IVA incluido', Printable::ALIGN_RIGHT);
        $this->vatBlock($p, $vatBreakdown, $columns);

        $this->footer($p->feed());

        return $p->line('NO VÁLIDO COMO FACTURA', Printable::ALIGN_CENTER, bold: true)
            ->line('Pida su ticket al pagar', Printable::ALIGN_CENTER)
            ->feed(3)->cut();
    }

    /** Nombre comercial, razón social, NIF, dirección y teléfono del emisor. */
    private function issuerHeader(Printable $p, array $issuer): void
    {
        $p->title($issuer['trade_name'] ?? '')
            ->line($issuer['legal_name'] ?? '', Printable::ALIGN_CENTER)
            ->line('NIF '.($issuer['tax_id'] ?? ''), Printable::ALIGN_CENTER);
        foreach (array_filter([$issuer['address'] ?? null, trim(($issuer['postal_code'] ?? '').' '.($issuer['city'] ?? '')), isset($issuer['phone']) ? 'Tel. '.$issuer['phone'] : null]) as $text) {
            $p->line($text, Printable::ALIGN_CENTER);
        }
        $p->separator('=');
    }

    /** Desglose de IVA (tipo · base · cuota · total). */
    private function vatBlock(Printable $p, array $breakdown, int $columns): void
    {
        $p->separator()->line($this->vatRow('IVA', 'Base', 'Cuota', 'Total', $columns), bold: true);
        foreach ($breakdown as $row) {
            $p->line($this->vatRow(Format::percent($row['rate']), Format::money($row['base']), Format::money($row['tax']), Format::money($row['total']), $columns));
        }
    }

    /** Pie de ticket de /admin (una línea por renglón). */
    private function footer(Printable $p): void
    {
        foreach (preg_split('/\R/', (string) Setting::current()->ticket_footer) as $footer) {
            if ($footer !== '') {
                $p->line($footer, Printable::ALIGN_CENTER);
            }
        }
    }

    /**
     * Ticket de domicilio: arriba y en grande lo que necesita el repartidor
     * (dirección, teléfono, indicaciones, hora, pago previsto y "cambio
     * para"), y debajo el ticket fiscal.
     */
    public function deliveryTicket(Ticket $ticket, Order $order, int $columns = 48): Printable
    {
        return $this->deliveryHeader(new Printable, $ticket, $order)
            ->separator('=')
            ->append($this->ticket($ticket, $columns));
    }

    /** Copia para el repartidor: datos de entrega, lo que lleva, importe y QR con la dirección en Google Maps. */
    public function courierCopy(Ticket $ticket, Order $order): Printable
    {
        $ticket->loadMissing('lines');
        $p = $this->deliveryHeader((new Printable)->line('COPIA REPARTIDOR', Printable::ALIGN_CENTER, bold: true), $ticket, $order)->separator();
        foreach ($ticket->lines->whereNull('parent_line_id') as $line) {
            $p->line($line->quantity.' '.$this->lineName($line));
        }
        $p->separator()->columns('Ticket '.$ticket->fullNumber(), Format::money($ticket->total), bold: true);
        if ($url = MapsLink::forAddress($order->delivery_address)) {
            $p->feed()->qr($url)->line('Escanea para abrir la dirección', Printable::ALIGN_CENTER);
        }

        return $p->feed(3)->cut();
    }

    private function deliveryHeader(Printable $p, Ticket $ticket, Order $order): Printable
    {
        $a = $order->delivery_address ?? [];
        // Parte de reparto con más aire entre líneas: se lee de un vistazo en la puerta.
        $p->lineSpacing(self::DELIVERY_LINE_SPACING);
        $p->line('DOMICILIO', Printable::ALIGN_CENTER, bold: true, large: true, reverse: true);
        $p->line(trim(($a['street'] ?? '').' '.($a['number'] ?? '')), bold: true, large: true);
        if (! empty($a['floor_door'])) {
            $p->line($a['floor_door'], bold: true, large: true);
        }
        $place = PostalCode::place($a['postal_code'] ?? null, $a['city'] ?? null);   // «17220 Sant Feliu de Guíxols (Girona)»
        if ($place !== '') {
            $p->line($place, bold: true);
        }
        if ($order->customer_name) {
            $p->line($order->customer_name, bold: true);
        }
        if ($order->customer_phone) {
            $p->line('Tel. '.$order->customer_phone, bold: true, large: true);
        }
        if (! empty($a['instructions'])) {
            $p->line('Indicaciones: '.$a['instructions'], bold: true);
        }
        $p->separator();
        if ($label = $order->promisedLabel()) {
            $p->columns('Hora', $label, bold: true);
        }
        if ($order->courier) {
            $p->columns('Repartidor', $order->courier->name);
        }

        if ($ticket->payment_status === PaymentStatus::Paid) {
            $method = $ticket->payments()->first()?->method ?? $order->prepaid_method;
            $p->line('PAGADO'.($method ? ' ('.$method->label().')' : ''), Printable::ALIGN_CENTER, bold: true, large: true);
        } else {
            $p->columns('Pago', $order->planned_payment_method?->label() ?? 'Al entregar', bold: true);
            if ($order->cash_change_for) {
                $p->line('CAMBIO PARA '.Format::money($order->cash_change_for), bold: true, large: true)
                    ->columns('  Devolver', Format::money(max(0, $order->cash_change_for - $ticket->total)));
            }
            $p->line('PENDIENTE DE COBRO', Printable::ALIGN_CENTER, bold: true, large: true, reverse: true)
                ->line('TOTAL A COBRAR '.Format::money($ticket->total), Printable::ALIGN_CENTER, bold: true, large: true);
        }

        $p->lineSpacing();   // el resto del ticket, con el interlineado normal

        return $p;
    }

    /** Resguardo de la liquidación de un repartidor. */
    public function courierSettlement(CourierSettlement $settlement): Printable
    {
        $p = (new Printable)->line('LIQUIDACIÓN DE REPARTO', Printable::ALIGN_CENTER, bold: true, large: true)
            ->line($settlement->courier->name, Printable::ALIGN_CENTER, bold: true, large: true)
            ->line($settlement->created_at->format('d/m/Y H:i').' · '.$settlement->settledBy->name, Printable::ALIGN_CENTER)
            ->separator('=');
        foreach ($settlement->orders as $order) {
            $ticket = $order->tickets->whereIn('type', TicketType::orderDocuments())->sortByDesc('id')->first();
            $p->columns(($ticket?->fullNumber() ?? "#{$order->id}").' '.($order->customer_name ?? ''), Format::money($ticket?->total ?? 0));
        }
        $p->separator()
            ->columns('Efectivo cobrado', Format::money($settlement->cash_collected))
            ->columns('Tarjeta', Format::money($settlement->card_collected));
        if ($settlement->other_collected) {
            $p->columns('Otros', Format::money($settlement->other_collected));
        }
        if ($settlement->float_amount) {
            $p->columns('Fondo devuelto', Format::money($settlement->float_amount));
        }

        return $p->columns('Efectivo esperado', Format::money($settlement->expected_cash), bold: true)
            ->columns('Entregado', Format::money($settlement->handed_cash), bold: true)
            ->columns('Diferencia', Format::money($settlement->difference), bold: true)
            ->feed(3)->cut();
    }

    /**
     * Factura completa en la impresora de tickets (80 mm = 48 columnas, 58 mm
     * = 32): todo el contenido del art. 6.1 (ver InvoiceDocument).
     */
    public function invoice(Ticket $ticket, int $columns = 48, bool $copy = false): Printable
    {
        $doc = InvoiceDocument::make($ticket);
        $p = new Printable;
        if ($copy) {
            $p->line('*** COPIA ***', Printable::ALIGN_CENTER, bold: true, reverse: true);
        }

        $this->taxQrBlock($p, $ticket);

        // Emisor (c, d, e)
        $issuer = $doc['issuer'];
        if ($issuer['trade_name']) {
            $p->title($issuer['trade_name']);
        }
        $p->line($issuer['name'], Printable::ALIGN_CENTER, bold: true)->line('NIF '.$issuer['tax_id'], Printable::ALIGN_CENTER);
        foreach (array_filter([$issuer['address'], $issuer['place'], $issuer['phone'] ? 'Tel. '.$issuer['phone'] : null]) as $text) {
            $p->line($text, Printable::ALIGN_CENTER);
        }
        $p->separator('=');

        // a, b, i
        $p->line($doc['title'], Printable::ALIGN_CENTER, bold: true, large: true)
            ->columns('Nº '.$doc['number'], $doc['issued_at'], bold: true);
        if ($doc['operation_date']) {
            $p->columns('Fecha de la operación', $doc['operation_date']);
        }
        if ($doc['table'] ?? null) {
            $p->line($doc['table']);
        }
        if ($doc['replaces']) {
            $p->line($doc['replaces']);
        }
        if ($r = $doc['rectifies']) {
            $p->line("Rectifica a la factura {$r['number']} de {$r['date']}", bold: true)
                ->line(ucfirst($r['method']));
            $p->line('Motivo: '.($r['reason'] ?: $r['reason_label']));
        }
        $p->separator();

        // Destinatario (c, d, e)
        $to = $doc['recipient'];
        $p->line('DESTINATARIO', bold: true)->line($to['name'], bold: true)->line('NIF '.$to['tax_id']);
        foreach (array_filter([$to['address'], $to['place'], $to['country'] ? 'País: '.$to['country'] : null]) as $text) {
            $p->line($text);
        }
        $p->separator();

        // Descripción con precio unitario sin IVA (f) y tipo (g)
        foreach ($doc['rows'] as $row) {
            $indent = $row['child'] ? '   · ' : '';
            $desc = $row['child'] ? $indent.$row['description'] : $row['quantity'].' x '.$row['description'];
            $row['amount'] !== null ? $p->columns($desc, Format::money($row['amount'])) : $p->line($desc);
            if ($row['unit_net'] !== null) {
                $p->line(($row['child'] ? '     ' : '   ').'P.u. sin IVA '.Format::unitNet($row['unit_net']).' · IVA '.Format::percent($row['vat_rate']));
            }
            if ($row['invitation']) {
                $p->columns('   Invitación', '-'.Format::money($row['discount']));
            } elseif ($row['discount']) {
                $p->columns('   Descuento', '-'.Format::money($row['discount']));
            }
        }
        $p->separator();
        // El envío ya va como una línea más (con su precio sin IVA y su tipo).
        if ($doc['lines_discount'] || $doc['discount_amount']) {
            $p->columns('Suma', Format::money($doc['subtotal'] - $doc['lines_discount'] + $doc['delivery_fee']));
            if ($doc['discount_amount']) {
                $p->columns('Descuento'.($doc['discount_reason'] ? " ({$doc['discount_reason']})" : ''), '-'.Format::money($doc['discount_amount']));
            }
        }

        // Base por tipo (6.2.c), cuota por separado (h) y total
        $p->line($this->vatRow('IVA', 'Base', 'Cuota', 'Total', $columns), bold: true);
        foreach ($doc['vat_breakdown'] as $row) {
            $p->line($this->vatRow(Format::percent($row['rate']), Format::money($row['base']), Format::money($row['tax']), Format::money($row['total']), $columns));
        }
        $p->columns('Base imponible', Format::money($doc['tax_base']))
            ->columns('Cuota IVA', Format::money($doc['tax_amount']))
            ->line('TOTAL  '.Format::money($doc['total']), Printable::ALIGN_RIGHT, bold: true, large: true);

        if ($ticket->payments->isNotEmpty()) {
            $p->separator();
            foreach ($ticket->payments as $payment) {
                $this->payment($p, $payment);
            }
        }
        $p->feed();
        foreach (preg_split('/\R/', $doc['footer']) as $footer) {
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
            $p->line('Z nº '.($report['z_full_number'] ?? $report['z_number']), Printable::ALIGN_CENTER, bold: true, large: true);
        }
        $p->columns('Apertura', $report['opened_at'])
            ->columns($final ? 'Cierre' : 'Hora', $report['generated_at'])
            ->separator('=');

        $p->line('VENTAS', bold: true)
            ->columns('Tickets ('.$report['tickets']['count'].')', Format::money($report['tickets']['total']))
            ->columns('Facturas ('.($report['invoices']['count'] ?? 0).')', Format::money($report['invoices']['total'] ?? 0))
            ->columns('Rectificativos ('.$report['rectifications']['count'].')', Format::money($report['rectifications']['total']))
            ->columns('TOTAL VENTAS', Format::money($report['sales_total']), bold: true)
            ->columns('Descuentos', Format::money($report['discounts']['amount']))
            ->columns('Invitaciones ('.$report['invitations']['count'].')', Format::money($report['invitations']['amount']));
        if ($report['exchanges']['count'] ?? 0) {
            $p->columns('Canjes por factura ('.$report['exchanges']['count'].')', Format::money($report['exchanges']['total']))
                ->line('  (no suman: el ticket ya se contó)');
        }
        if ($report['annulled']['count'] ?? 0) {
            $p->columns('Anulados por error ('.$report['annulled']['count'].')', Format::money($report['annulled']['total']))
                ->line('  (no suman: no hubo venta)');
        }
        $p->separator();

        $p->line('FORMAS DE PAGO', bold: true);
        foreach ($report['by_method'] as $row) {
            if ($row['refunds_count'] ?? 0) {
                $p->columns($row['label'].' cobros ('.$row['count'].')', Format::money($row['charges']))
                    ->columns('Devoluciones '.mb_strtolower($row['label']).' ('.$row['refunds_count'].')', Format::money($row['refunds']))
                    ->columns($row['label'].' neto', Format::money($row['amount']), bold: true);
            } else {
                $p->columns($row['label'].' ('.$row['count'].')', Format::money($row['amount']), bold: true);
            }
        }
        if ($report['card_terminal_total'] ?? 0) {
            $p->columns('TOTAL DATÁFONO (tarjeta + propinas)', Format::money($report['card_terminal_total']), bold: true);
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

        if (! empty($report['courier_settlements'])) {
            $p->separator()->line('LIQUIDACIONES DE REPARTO', bold: true);
            foreach ($report['courier_settlements'] as $row) {
                $p->columns($row['courier'], 'Ef. '.Format::money($row['cash']).' · Tarj. '.Format::money($row['card']));
                if ($row['difference']) {
                    $p->columns('  Diferencia', Format::money($row['difference']), bold: true);
                }
            }
        }
        if (! empty($report['delivery_pending']['count'])) {
            $p->separator()->line('DOMICILIO PENDIENTE DE COBRO', bold: true, reverse: true);
            foreach ($report['delivery_pending']['tickets'] as $row) {
                $p->columns($row['number'].' · '.($row['courier'] ?? 'sin repartidor'), Format::money($row['amount']));
            }
            $p->columns('Total pendiente', Format::money($report['delivery_pending']['total']), bold: true);
        }
        if (! empty($report['cash_movements'])) {
            $p->separator()->line('MOVIMIENTOS DE CAJA (no son ventas)', bold: true);
            foreach ($report['cash_movements'] as $row) {
                $p->columns($row['at'].' '.$row['label'].($row['courier'] ? " · {$row['courier']}" : ''), Format::money($row['amount']));
            }
        }

        $p->separator('=')->line('EFECTIVO', bold: true)
            ->columns('Fondo inicial', Format::money($report['cash']['opening_float']))
            ->columns('Cobros en efectivo', Format::money($report['cash']['cash_in']));
        if ($report['cash']['movements'] ?? 0) {
            $p->columns('Movimientos de caja', Format::money($report['cash']['movements']));
        }
        $p->columns('Efectivo esperado', Format::money($report['cash']['expected']), bold: true);
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
    /**
     * QR tributario al principio del documento, antes de su contenido, con
     * «QR tributario:» encima y «VERI*FACTU» debajo (OM 20 y 21; especificación
     * del QR, apdo. 3). Solo si el documento tiene registro de facturación.
     */
    private function taxQrBlock(Printable $p, Ticket $ticket): void
    {
        if (! $url = TaxQr::for($ticket)) {
            return;
        }
        $p->line('QR tributario:', Printable::ALIGN_CENTER, bold: true)
            ->taxQr($url)
            ->line(TaxQr::LEGEND_SHORT, Printable::ALIGN_CENTER, bold: true)
            ->feed();
    }

    /** Constancia del suplemento por mitad y mitad (ya incluido en el importe de la línea). */
    private function halfSurcharge(Printable $p, ?int $surcharge): void
    {
        if ($surcharge) {
            $p->line('   + Suplemento mitad y mitad '.Format::money($surcharge));
        }
    }

    private function lineName(TicketLine $line): string
    {
        $half = $line->kind->value === 'mitad' ? '½ ' : '';

        return $half.$line->name.($line->variant_name ? " ({$line->variant_name})" : '');
    }

    private function orderLineName(OrderLine $line): string
    {
        $half = $line->kind->value === 'mitad' ? '½ ' : '';

        return $half.$line->name.($line->variant_name ? " ({$line->variant_name})" : '');
    }

    private function where(Ticket $ticket): string
    {
        if ($ticket->table_label) {
            return $ticket->table_label;   // «Mesa 3 + 4», copiado al emitir
        }
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
