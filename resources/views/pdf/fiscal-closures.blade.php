@php($m = fn ($c) => \App\Support\Format::money($c))
<html><head><meta charset="utf-8">@include('pdf._style')</head><body>
<h1>Cierres de caja Z · {{ $settings->legal_name }} (NIF {{ $settings->tax_id }})</h1>
<p class="meta">Periodo {{ $from->format('d/m/Y') }} – {{ $to->format('d/m/Y') }} · generado {{ now()->format('d/m/Y H:i') }}</p>
<table>
    <thead><tr><th>Z nº</th><th>Apertura</th><th>Cierre</th><th>Cerró</th><th>Tickets</th><th>Ventas</th><th>Fondo</th><th>Esperado</th><th>Contado</th><th>Diferencia</th><th>Propinas</th></tr></thead>
    <tbody>
    @foreach ($closures as $c)
        <tr>
            <td>{{ $c->z_number }}</td><td>{{ $c->opened_at->format('d/m/Y H:i') }}</td><td>{{ $c->closed_at?->format('d/m/Y H:i') }}</td>
            <td>{{ $c->closedBy?->name }}</td><td class="n">{{ $c->z_report['tickets']['count'] ?? '' }}</td>
            <td class="n">{{ $m($c->z_report['sales_total'] ?? 0) }}</td><td class="n">{{ $m($c->opening_float) }}</td>
            <td class="n">{{ $m($c->expected_cash) }}</td><td class="n">{{ $m($c->counted_cash) }}</td><td class="n">{{ $m($c->difference) }}</td>
            <td class="n">{{ $m($c->z_report['tips']['total'] ?? 0) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@foreach ($closures as $c)
    @if ($c->z_report)
        <h2>Z nº {{ $c->z_number }} · formas de pago e IVA</h2>
        <table>
            <thead><tr><th>Forma de pago</th><th>Cobros</th><th>Importe</th></tr></thead>
            <tbody>@foreach ($c->z_report['by_method'] as $r)<tr><td>{{ $r['label'] }}</td><td class="n">{{ $r['count'] }}</td><td class="n">{{ $m($r['amount']) }}</td></tr>@endforeach</tbody>
        </table>
        <table>
            <thead><tr><th>Tipo IVA</th><th>Base</th><th>Cuota</th><th>Total</th></tr></thead>
            <tbody>@foreach ($c->z_report['by_vat'] as $v)<tr><td>{{ \App\Support\Format::percent($v['rate']) }}</td><td class="n">{{ $m($v['base']) }}</td><td class="n">{{ $m($v['tax']) }}</td><td class="n">{{ $m($v['total']) }}</td></tr>@endforeach</tbody>
        </table>
    @endif
@endforeach
</body></html>
