<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Copias de seguridad (ver docs/instalacion-minipc.md) -------------------
// Diaria para los locales que no apagan el equipo por la noche.
Schedule::command('tpv:backup --reason=diaria')->dailyAt('05:00')->withoutOverlapping();
// Al arrancar el equipo (o en cualquier momento) si la última copia correcta tiene más de 24 h.
Schedule::command('tpv:backup --reason=arranque --if-older=24')->everyFifteenMinutes()->withoutOverlapping();
// Sin internet, la subida remota se reintenta cada hora.
Schedule::command('tpv:backup --upload-only')->hourly()->withoutOverlapping();

// Facturas por email que no salieron (sin internet): se reintentan cada hora.
Schedule::command('tpv:invoices-retry-email')->hourly()->withoutOverlapping();
