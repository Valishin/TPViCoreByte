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
    // Carpeta compartida con el equipo para unirlo a Tailscale (App\Support\TailscaleBridge).
    'tailscale_bridge' => env('TPV_TAILSCALE_BRIDGE', '/var/tpv/host/tailscale'),

    'install_mode' => env('TPV_INSTALL_MODE', 'cliente') === 'demo' ? 'demo' : 'cliente',

    // Soporte técnico (iCOREBYTE): sale abajo en la entrada de la TPV y de /admin, y en «Acerca de».
    'support' => [
        'name' => 'iCOREBYTE',
        'phone' => env('TPV_SUPPORT_PHONE', '678 758 210'),       // también WhatsApp
        'email' => env('TPV_SUPPORT_EMAIL', 'info@icorebyte.com'),
        'web' => env('TPV_SUPPORT_WEB', 'icorebyte.com'),
    ],
];
