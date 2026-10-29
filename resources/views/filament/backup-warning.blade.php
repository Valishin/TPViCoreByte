@php
    /** Aviso de copias de seguridad en /admin (ver App\Support\BackupStatus). */
    $warning = \App\Support\BackupStatus::warning();
@endphp
@if ($warning)
    <div role="alert" style="margin:0 0 1rem;padding:.75rem 1rem;border-radius:.5rem;border:2px solid #b45309;background:#fef3c7;color:#451a03;font-weight:600">
        ⚠ {{ $warning }}
    </div>
@endif
