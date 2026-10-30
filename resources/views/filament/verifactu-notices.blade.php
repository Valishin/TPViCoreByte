@php
    /** Avisos persistentes de VERI*FACTU en /admin (App\Services\Verifactu\VerifactuStatus; OM 16.4, 6.f y 7.j). */
    $notices = \App\Models\Setting::query()->exists() ? \App\Services\Verifactu\VerifactuStatus::notices() : [];
@endphp
@foreach ($notices as $notice)
    <div role="alert" style="margin:0 0 1rem;padding:.75rem 1rem;border-radius:.5rem;font-weight:600;{{ $notice['level'] === 'danger'
        ? 'border:2px solid #b91c1c;background:#fee2e2;color:#450a0a'
        : 'border:2px solid #b45309;background:#fef3c7;color:#451a03' }}">
        ⚠ {{ $notice['text'] }}
        @if (\App\Filament\Pages\Verifactu::canAccess())
            · <a href="{{ \App\Filament\Pages\Verifactu::getUrl() }}" style="text-decoration:underline">Ver VERI*FACTU</a>
        @endif
    </div>
@endforeach
