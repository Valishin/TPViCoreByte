@php($s = config('tpv.support'))
@php($digits = preg_replace('/\D/', '', $s['phone']))
@if (empty($inline))
<div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid rgba(127,127,127,.25);text-align:center;font-size:.85rem;line-height:1.6;opacity:.85">
    <div style="font-weight:600">Soporte técnico · {{ $s['name'] }}</div>
@endif
    <a href="tel:+34{{ $digits }}" style="text-decoration:underline">Tel. {{ $s['phone'] }}</a>
    · <a href="https://wa.me/34{{ $digits }}" target="_blank" rel="noopener" style="text-decoration:underline">WhatsApp</a>
    · <a href="https://{{ $s['web'] }}" target="_blank" rel="noopener" style="text-decoration:underline">{{ $s['web'] }}</a>
    · <a href="mailto:{{ $s['email'] }}" style="text-decoration:underline">{{ $s['email'] }}</a>
@if (empty($inline))
</div>
@endif
