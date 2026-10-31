@php use App\Support\Format; @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $doc['title'] }} {{ $doc['number'] }}</title>
<style>
    @page { margin: 18mm 15mm 20mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #111; }
    .head { width: 100%; margin-bottom: 14px; }
    .head td { vertical-align: top; border: 0; padding: 0; }
    .logo { max-height: 60px; max-width: 180px; margin-bottom: 6px; }
    h1 { font-size: 18px; margin: 0; letter-spacing: .5px; }
    .number { font-size: 12px; font-weight: bold; margin-top: 4px; }
    .muted { color: #555; }
    .parties { width: 100%; margin: 6px 0 14px; border-collapse: separate; border-spacing: 8px 0; }
    .parties td { width: 50%; vertical-align: top; border: 1px solid #bbb; padding: 8px; }
    .label { font-size: 8px; text-transform: uppercase; color: #666; letter-spacing: .6px; margin-bottom: 3px; }
    .name { font-weight: bold; font-size: 11px; }
    table.lines { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    table.lines th { background: #eee; text-align: left; border-bottom: 1px solid #999; padding: 4px; font-size: 8.5px; }
    table.lines td { border-bottom: 1px solid #ddd; padding: 4px; }
    .n { text-align: right; white-space: nowrap; }
    .child td { color: #333; }
    .child td.desc { padding-left: 16px; }
    .note td { color: #555; font-style: italic; }
    .totals { width: 48%; margin-left: 52%; border-collapse: collapse; }
    .totals td { padding: 3px 4px; border-bottom: 1px solid #eee; }
    .totals .grand td { font-size: 13px; font-weight: bold; border-top: 2px solid #111; }
    table.vat { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.vat th, table.vat td { border: 1px solid #ccc; padding: 3px 4px; }
    table.vat th { background: #f2f2f2; }
    .box { border: 1px solid #bbb; padding: 6px 8px; margin-bottom: 10px; }
    /* QR tributario arriba, centrado, antes del contenido (especificación del QR, apdo. 3 y ejemplo h). */
    .verifactu { text-align: center; margin: 0 0 10px; }
    .verifactu .qr-label, .verifactu .qr-legend { font-size: 11px; font-weight: bold; }
    .verifactu img { display: block; margin: 2px auto; }
    .footer { margin-top: 18px; text-align: center; color: #555; font-size: 8.5px; }
</style>
</head>
<body>
@if ($qr)
    <div class="verifactu">
        <div class="qr-label">QR tributario:</div>
        <img src="{{ $qr['src'] }}" style="width: {{ $qr['width_mm'] }}mm; height: {{ $qr['width_mm'] }}mm" alt="QR tributario">
        <div class="qr-legend">{{ \App\Services\Verifactu\TaxQr::LEGEND_LONG }}</div>
    </div>
@endif
<table class="head"><tr>
    <td>
        @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
        @if ($doc['issuer']['trade_name'])<div class="name">{{ $doc['issuer']['trade_name'] }}</div>@endif
    </td>
    <td style="text-align:right">
        <h1>{{ $doc['title'] }}</h1>
        <div class="number">Nº {{ $doc['number'] }}</div>
        <div>Fecha de expedición: {{ $doc['issued_date'] }}</div>
        @if ($doc['operation_date'])<div>Fecha de la operación: {{ $doc['operation_date'] }}</div>@endif
        @if ($doc['table'] ?? null)<div class="muted">{{ $doc['table'] }}</div>@endif
    </td>
</tr></table>

<table class="parties"><tr>
    @foreach (['Emisor' => $doc['issuer'], 'Destinatario' => $doc['recipient']] as $label => $party)
        <td>
            <div class="label">{{ $label }}</div>
            <div class="name">{{ $party['name'] }}</div>
            <div>NIF {{ $party['tax_id'] }}</div>
            @if ($party['address'])<div>{{ $party['address'] }}</div>@endif
            @if ($party['place'])<div>{{ $party['place'] }}</div>@endif
            @if ($party['country'])<div>País: {{ $party['country'] }}</div>@endif
            @if ($party['phone'])<div class="muted">Tel. {{ $party['phone'] }}</div>@endif
        </td>
    @endforeach
</tr></table>

@if ($doc['replaces'])<div class="box">{{ $doc['replaces'] }}.</div>@endif
@if ($r = $doc['rectifies'])
    <div class="box">
        <strong>Rectifica a la factura {{ $r['number'] }} de {{ $r['date'] }}</strong>, {{ $r['method'] }}.<br>
        Motivo: {{ $r['reason'] ?: $r['reason_label'] }}
    </div>
@endif

<table class="lines">
    <thead><tr>
        <th>Descripción</th><th class="n">Uds.</th><th class="n">Precio unitario sin IVA</th><th class="n">IVA</th><th class="n">Importe (IVA incl.)</th>
    </tr></thead>
    <tbody>
    @foreach ($doc['rows'] as $row)
        <tr class="{{ $row['child'] ? 'child' : '' }}">
            <td class="desc">{{ $row['child'] ? '· ' : '' }}{{ $row['description'] }}</td>
            <td class="n">{{ $row['child'] ? '' : $row['quantity'] }}</td>
            <td class="n">{{ $row['unit_net'] !== null ? Format::unitNet($row['unit_net']) : '' }}</td>
            <td class="n">{{ $row['vat_rate'] !== null ? Format::percent($row['vat_rate']) : '' }}</td>
            <td class="n">{{ $row['amount'] !== null ? Format::money($row['amount']) : '' }}</td>
        </tr>
        @if ($row['discount'])
            <tr class="note"><td class="desc" colspan="4">{{ $row['invitation'] ? 'Invitación' : 'Descuento' }}</td><td class="n">-{{ Format::money($row['discount']) }}</td></tr>
        @endif
    @endforeach
    @if ($doc['discount_amount'])
        <tr class="note"><td colspan="4">Descuento{{ $doc['discount_reason'] ? ' ('.$doc['discount_reason'].')' : '' }}</td><td class="n">-{{ Format::money($doc['discount_amount']) }}</td></tr>
    @endif
    </tbody>
</table>

<table class="vat">
    <thead><tr><th>Tipo de IVA</th><th class="n">Base imponible</th><th class="n">Cuota</th><th class="n">Total</th></tr></thead>
    <tbody>
    @foreach ($doc['vat_breakdown'] as $row)
        <tr><td>{{ Format::percent($row['rate']) }}</td><td class="n">{{ Format::money($row['base']) }}</td><td class="n">{{ Format::money($row['tax']) }}</td><td class="n">{{ Format::money($row['total']) }}</td></tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Base imponible</td><td class="n">{{ Format::money($doc['tax_base']) }}</td></tr>
    <tr><td>Cuota de IVA</td><td class="n">{{ Format::money($doc['tax_amount']) }}</td></tr>
    <tr class="grand"><td>TOTAL</td><td class="n">{{ Format::money($doc['total']) }}</td></tr>
</table>

@if ($doc['payments']->isNotEmpty())
    <p class="muted">Forma de pago: {{ $doc['payments']->map(fn ($p) => $p->method->label().' '.Format::money($p->amount))->implode(' · ') }}</p>
@endif


@if (trim($doc['footer']) !== '')<div class="footer">{!! nl2br(e($doc['footer'])) !!}</div>@endif
</body>
</html>
