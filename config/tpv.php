<?php

return [
    // Modo solo consulta (licencia caducada…). Ver App\Support\ReadOnlyMode.
    'read_only' => env('TPV_READ_ONLY', false),

    // Zona horaria del año de numeración de tickets, facturas y cierres Z
    // (App\Support\DocumentNumber). Hora peninsular; en Canarias sería
    // Atlantic/Canary.
    'fiscal_timezone' => env('TPV_FISCAL_TIMEZONE', 'Europe/Madrid'),

    // Tipo de instalación (lo pone install.sh): «cliente» (por defecto) emite
    // documentos reales y aplica los bloqueos (datos fiscales, VERI*FACTU);
    // «demo» vende con datos de ejemplo, con VERI*FACTU apagado y sin avisos.
    'install_mode' => env('TPV_INSTALL_MODE', 'cliente') === 'demo' ? 'demo' : 'cliente',
];
