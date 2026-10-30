@php($m = fn ($c) => \App\Support\Format::money($c))
<html><head><meta charset="utf-8">@include('pdf._style')</head><body>
<h1>Tickets emitidos · {{ $settings->legal_name }} (NIF {{ $settings->tax_id }})</h1>
<p class="meta">Periodo {{ $from->format('d/m/Y') }} – {{ $to->format('d/m/Y') }} · generado {{ now()->format('d/m/Y H:i') }}</p>

<h2>Resumen de IVA</h2>
<table>
    <thead><tr><th>Tipo</th><th>Base imponible</th><th>Cuota</th><th>Total</th></tr></thead>
    <tbody>
    @foreach ($vatTotals as $v)
        <tr><td>{{ \App\Support\Format::percent($v['rate']) }}</td><td class="n">{{ $m($v['base']) }}</td><td class="n">{{ $m($v['tax']) }}</td><td class="n">{{ $m($v['total']) }}</td></tr>
    @endforeach
    </tbody>
</table>

@foreach (['Facturas simplificadas' => $simplified, 'Facturas completas (directas y canjes)' => $invoices ?? collect(), 'Facturas rectificativas' => $rectifying] as $title => $list)
    <h2>{{ $title }} ({{ $list->count() }})</h2>
    <table>
        <thead><tr><th>Número</th><th>Fecha</th><th>Pedido</th><th>Atendió</th><th>Referencia</th><th>Destinatario</th><th>Formas de pago</th><th>Base</th><th>Cuota</th><th>Total</th></tr></thead>
        <tbody>
        @foreach ($list as $t)
            <tr>
                <td>{{ $t->fullNumber() }}</td><td>{{ $t->issued_at->format('d/m/Y H:i') }}</td>
                <td>{{ $t->order_type?->label() }}</td><td>{{ $t->issuedBy?->name }}</td>
                <td>{{ $t->rectifiedTicket ? 'Rectifica '.$t->rectifiedTicket->fullNumber() : ($t->replacedTicket ? 'Sustituye '.$t->replacedTicket->fullNumber() : ($t->exchangedBy ? 'Canjeado: '.$t->exchangedBy->fullNumber() : '')) }}</td>
                <td>{{ $t->recipient ? ($t->recipient['tax_id'] ?? '').' '.($t->recipient['name'] ?? '') : '' }}</td>
                <td>{{ $t->payments->map(fn ($p) => $p->method->label())->unique()->implode(', ') }}</td>
                <td class="n">{{ $m($t->tax_base) }}</td><td class="n">{{ $m($t->tax_amount) }}</td><td class="n">{{ $m($t->total) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="7">Total</td><td class="n">{{ $m($list->sum('tax_base')) }}</td><td class="n">{{ $m($list->sum('tax_amount')) }}</td><td class="n">{{ $m($list->sum('total')) }}</td></tr></tfoot>
    </table>
@endforeach
</body></html>
