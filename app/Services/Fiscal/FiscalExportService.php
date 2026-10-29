<?php

namespace App\Services\Fiscal;

use App\Enums\CashSessionStatus;
use App\Enums\TicketType;
use App\Models\CashSession;
use App\Models\Setting;
use App\Models\Ticket;
use App\Support\AllowedInReadOnly;
use App\Support\Format;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use ZipArchive;

/**
 * Exportación de datos fiscales por rango de fechas: tickets (con líneas y
 * desglose de IVA), rectificativos y cierres Z, en CSV y PDF dentro de un ZIP.
 *
 * Debe funcionar SIEMPRE, también en modo solo consulta: solo lee datos.
 */
#[AllowedInReadOnly]
class FiscalExportService
{
    /** @return string ruta absoluta del ZIP generado */
    public function export(Carbon $from, Carbon $to): string
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $tickets = Ticket::query()->whereBetween('issued_at', [$from, $to])
            ->with(['lines', 'payments', 'issuedBy', 'rectifiedTicket'])
            ->orderBy('issued_at')->orderBy('id')->get();
        $closures = CashSession::query()->where('status', CashSessionStatus::Closed)->whereBetween('closed_at', [$from, $to])
            ->with(['openedBy', 'closedBy'])->orderBy('z_number')->get();

        $dir = storage_path('app/private/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = 'exportacion-fiscal_'.$from->format('Ymd').'-'.$to->format('Ymd').'_'.now()->format('His');
        $path = "{$dir}/{$name}.zip";

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se ha podido crear el ZIP.');
        }

        $simplified = $tickets->where('type', TicketType::Simplified);
        $rectifying = $tickets->where('type', TicketType::Rectifying);

        $zip->addFromString('tickets.csv', $this->csv($this->ticketRows($simplified)));
        $zip->addFromString('rectificativos.csv', $this->csv($this->ticketRows($rectifying, rectifying: true)));
        $zip->addFromString('lineas.csv', $this->csv($this->lineRows($tickets)));
        $zip->addFromString('desglose_iva.csv', $this->csv($this->vatRows($tickets)));
        $zip->addFromString('cierres_z.csv', $this->csv($this->closureRows($closures)));

        $data = [
            'settings' => Setting::current(),
            'from' => $from, 'to' => $to,
            'simplified' => $simplified, 'rectifying' => $rectifying, 'closures' => $closures,
            'vatTotals' => $this->vatTotals($tickets),
        ];
        $zip->addFromString('tickets.pdf', Pdf::loadView('pdf.fiscal-tickets', $data)->setPaper('a4', 'landscape')->output());
        $zip->addFromString('cierres_z.pdf', Pdf::loadView('pdf.fiscal-closures', $data)->setPaper('a4')->output());
        $zip->addFromString('LEEME.txt', $this->readme($from, $to, $simplified->count(), $rectifying->count(), $closures->count()));
        $zip->close();

        return $path;
    }

    private function ticketRows(Collection $tickets, bool $rectifying = false): array
    {
        $rows = [array_merge(
            ['Serie', 'Número', 'Fecha', 'Hora', 'Estado', 'Tipo de pedido', 'Atendió', 'Base imponible', 'Cuota IVA', 'Total', 'Formas de pago', 'NIF emisor'],
            $rectifying ? ['Rectifica a', 'Método', 'Motivo'] : [],
        )];
        foreach ($tickets as $t) {
            $rows[] = array_merge([
                $t->series_code, $t->number, $t->issued_at->format('d/m/Y'), $t->issued_at->format('H:i:s'),
                $t->status->label(), $t->order_type?->label() ?? '', $t->issuedBy?->name ?? '',
                $this->amount($t->tax_base), $this->amount($t->tax_amount), $this->amount($t->total),
                $t->payments->map(fn ($p) => $p->method->label().' '.$this->amount($p->amount))->implode(' + '),
                $t->issuer['tax_id'] ?? '',
            ], $rectifying ? [$t->rectifiedTicket?->fullNumber() ?? '', $t->rectification_method?->label() ?? '', $t->rectification_reason ?? ''] : []);
        }

        return $rows;
    }

    private function lineRows(Collection $tickets): array
    {
        $rows = [['Serie', 'Número', 'Línea', 'Línea padre', 'Cantidad', 'Producto', 'Variante', 'Tipo IVA', 'Importe', 'Descuento', 'Invitación', 'Importe tributable']];
        foreach ($tickets as $t) {
            foreach ($t->lines as $l) {
                $rows[] = [
                    $t->series_code, $t->number, $l->id, $l->parent_line_id ?? '', $l->quantity, $l->name, $l->variant_name ?? '',
                    Format::percentInput($l->vat_rate), $this->amount($l->line_total), $this->amount($l->discount_amount + $l->order_discount_amount),
                    $l->is_invitation ? 'Sí' : 'No', $this->amount($l->taxable_amount),
                ];
            }
        }

        return $rows;
    }

    private function vatRows(Collection $tickets): array
    {
        $rows = [['Serie', 'Número', 'Fecha', 'Tipo IVA (%)', 'Base imponible', 'Cuota', 'Total']];
        foreach ($tickets as $t) {
            foreach ($t->vat_breakdown as $v) {
                $rows[] = [$t->series_code, $t->number, $t->issued_at->format('d/m/Y'), Format::percentInput($v['rate']), $this->amount($v['base']), $this->amount($v['tax']), $this->amount($v['total'])];
            }
        }

        return $rows;
    }

    private function closureRows(Collection $closures): array
    {
        $rows = [['Z nº', 'Apertura', 'Cierre', 'Abrió', 'Cerró', 'Fondo inicial', 'Efectivo esperado', 'Contado', 'Diferencia', 'Tickets', 'Rectificativos', 'Total ventas', 'Propinas']];
        foreach ($closures as $c) {
            $r = $c->z_report ?? [];
            $rows[] = [
                $c->z_number, $c->opened_at->format('d/m/Y H:i'), $c->closed_at?->format('d/m/Y H:i') ?? '',
                $c->openedBy?->name ?? '', $c->closedBy?->name ?? '',
                $this->amount($c->opening_float), $this->amount($c->expected_cash), $this->amount($c->counted_cash), $this->amount($c->difference),
                $r['tickets']['count'] ?? '', $r['rectifications']['count'] ?? '', $this->amount($r['sales_total'] ?? null), $this->amount($r['tips']['total'] ?? null),
            ];
        }

        return $rows;
    }

    /** @return list<array{rate: int, base: int, tax: int, total: int}> */
    public function vatTotals(Collection $tickets): array
    {
        $totals = [];
        foreach ($tickets as $t) {
            foreach ($t->vat_breakdown as $v) {
                $totals[$v['rate']] ??= ['rate' => $v['rate'], 'base' => 0, 'tax' => 0, 'total' => 0];
                foreach (['base', 'tax', 'total'] as $k) {
                    $totals[$v['rate']][$k] += $v[$k];
                }
            }
        }
        ksort($totals);

        return array_values($totals);
    }

    /** CSV para Excel en español: separador ";", UTF-8 con BOM. */
    private function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}");
        foreach ($rows as $row) {
            fputcsv($out, $row, ';', '"', '');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /** 1250 → "12,50" (sin símbolo, para hojas de cálculo). */
    private function amount(?int $cents): string
    {
        return $cents === null ? '' : Format::moneyInput($cents);
    }

    private function readme(Carbon $from, Carbon $to, int $tickets, int $rectifying, int $closures): string
    {
        $s = Setting::current();

        return "Exportación de datos fiscales\n{$s->legal_name} · NIF {$s->tax_id}\n"
            .'Periodo: '.$from->format('d/m/Y').' – '.$to->format('d/m/Y')."\nGenerado: ".now()->format('d/m/Y H:i')."\n\n"
            ."tickets.csv / tickets.pdf   Facturas simplificadas ({$tickets})\n"
            ."rectificativos.csv          Facturas rectificativas ({$rectifying})\n"
            ."lineas.csv                  Líneas de todos los tickets\n"
            ."desglose_iva.csv            Desglose de IVA por ticket y tipo\n"
            ."cierres_z.csv / .pdf        Cierres de caja Z ({$closures})\n\n"
            ."Importes en euros con coma decimal, IVA incluido salvo base y cuota. CSV separado por punto y coma.\n";
    }
}
