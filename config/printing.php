<?php

return [

    /*
    | Modo de impresión:
    |  - simulated: no envía nada; guarda el ticket como texto legible (y los
    |    bytes ESC/POS en .bin) en storage/app/prints/.
    |  - network: impresión REAL. Cada impresora usa su conexión: red (TCP,
    |    normalmente puerto 9100) o USB (dispositivo /dev/...).
    */
    'mode' => env('PRINTER_MODE', 'simulated'),

    // Conexión de la configuración global (comando tpv:print-test sin impresora): red | usb
    'connection' => env('PRINTER_CONNECTION', 'red'),

    'usb' => [
        'device_path' => env('PRINTER_DEVICE', '/dev/usb/lp0'),
    ],

    'network' => [
        'host' => env('PRINTER_HOST', '192.168.1.200'),
        'port' => (int) env('PRINTER_PORT', 9100),
        'timeout' => (int) env('PRINTER_TIMEOUT', 3), // corto: una impresora caída no debe bloquear
    ],

    // Perfil de capacidades de escpos-php (default, simple, TM-T88V, ...).
    'profile' => env('PRINTER_PROFILE', 'default'),

    /*
    | Página de códigos (número de tabla ESC t n de la impresora).
    | 16 = WPC1252 (á, é, ñ, €) en la mayoría de Epson y clónicas.
    | Vacío = selección automática de escpos-php según el perfil.
    */
    'codepage' => env('PRINTER_CODEPAGE', 16),

    // Caracteres por línea: 48 en 80 mm (fuente A), 32 en 58 mm.
    'columns' => (int) env('PRINTER_COLUMNS', 48),

    'simulated_path' => env('PRINTER_SIMULATED_PATH', 'prints'),

];
