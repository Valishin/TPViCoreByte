{{-- Marca «iCOREBYTE | TPV» (login de /admin y primer acceso). Misma forma que BrandMark.vue de la TPV. --}}
@php($h = $height ?? 40)
<span role="img" aria-label="iCOREBYTE TPV" style="display:inline-flex;align-items:center;gap:{{ round($h * 0.32) }}px;line-height:1;vertical-align:middle">
    <img src="{{ asset('images/icorebyte-logo.png') }}" alt="" style="height:{{ $h }}px;width:auto;display:block">
    <span aria-hidden="true" style="width:2px;height:{{ round($h * 0.78) }}px;background:#016be2;opacity:.35;border-radius:1px"></span>
    <span aria-hidden="true" style="font-family:'Arial Black','Helvetica Neue',Arial,system-ui,sans-serif;font-weight:900;font-size:{{ round($h * 1.18) }}px;letter-spacing:.04em;color:#016be2;line-height:.8">TPV</span>
</span>
