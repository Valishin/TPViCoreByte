<?php

/*
 * VERI*FACTU — configuración del PRODUCTOR y de la instalación.
 *
 * Estos datos los fija el productor (iCOREBYTE) en el código o el instalador
 * (variables de entorno del .env de /opt/tpv, propiedad de root). El cliente no
 * puede cambiarlos desde /admin. Ver docs/verifactu/PLAN.md (D27).
 *
 * Bloque SistemaInformatico (Diseños de registro, hoja 5; Validaciones 3.1.5).
 */
return [
    'producer' => [
        // Persona o entidad productora del sistema (NombreRazon / NIF, Alfanumérico 120 / NIF 9).
        'name' => env('VERIFACTU_PRODUCER_NAME', 'Alex Valishin Abubekirov'),
        'nif' => env('VERIFACTU_PRODUCER_NIF', '49298273G'),
        'address' => env('VERIFACTU_PRODUCER_ADDRESS', 'Carretera de Palamós 57, bajos. 17220 Sant Feliu de Guíxols (Girona). España'),
        // Lugar de firma de la declaración responsable (OM 15.1.l): localidad y país.
        'signature_place' => env('VERIFACTU_PRODUCER_SIGNATURE_PLACE', 'Sant Feliu de Guíxols, España'),
    ],

    'system' => [
        // NombreSistemaInformatico (máx. 30) e IdSistemaInformatico (2: A-Z sin Ñ, 0-9).
        'name' => env('VERIFACTU_SYSTEM_NAME', 'iCOREBYTE TPV'),
        'id' => env('VERIFACTU_SYSTEM_ID', 'IC'),
        // Version: la de la release instalada (fichero VERSION); no se configura a mano.
        'version' => env('APP_VERSION') ?: trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',
        // TipoUsoPosibleSoloVerifactu = S: este sistema solo funciona como VERI*FACTU (sin firma ni eventos).
        'only_verifactu' => true,
        // TipoUsoPosibleMultiOT = N: una instalación factura para un solo obligado tributario (OM 2).
        'multi_taxpayer' => false,
    ],

    // NumeroInstalacion (máx. 100): lo genera el instalador (fecha-hora + aleatorio) y no se repite nunca
    // (FAQ 4). Restaurar en otro equipo = reinstalación con número nuevo (Q6).
    'installation_number' => env('VERIFACTU_INSTALLATION_NUMBER', 'DEV-'.substr(md5((string) env('APP_KEY')), 0, 12)),

    // Fecha a partir de la cual el cliente está obligado (RDL 15/2025): 2027-01-01 si declara el
    // Impuesto sobre Sociedades; 2027-07-01 en el resto. Se elige en /admin (paso 2 de la puesta
    // en marcha, settings.corporate_tax); sin contestar, la del .env (instalaciones anteriores).
    'obligation_dates' => ['sociedades' => '2027-01-01', 'resto' => '2027-07-01'],
    'obligation_date' => env('VERIFACTU_OBLIGATION_DATE', '2027-07-01'),

    // Días antes de la fecha de obligación desde los que se avisa si VERI*FACTU no está activo (Q5).
    'obligation_warning_days' => 30,

    // Horas con registros sin enviar a partir de las cuales el aviso pasa a alarma.
    'pending_alarm_hours' => (int) env('VERIFACTU_PENDING_ALARM_HOURS', 4),

    // Servicios web (WSDL tikeV1.0, puertos SistemaVerifactu*; Descripción servicios web 7 y 8).
    'endpoints' => [
        'pruebas' => [
            'personal' => 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
            'sello' => 'https://prewww10.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
        ],
        'produccion' => [
            'personal' => 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
            'sello' => 'https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
        ],
    ],

    // URL del código QR (Especificaciones QR 5.1).
    'qr_url' => [
        'pruebas' => 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR',
        'produccion' => 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR',
    ],

    // Máximo de registros por envío (Diseños de registro 1: RegistroFactura 1-1000; OM 16.2).
    'max_records_per_submission' => 1000,

    // Segundos de espera de la petición HTTP.
    'timeout' => (int) env('VERIFACTU_TIMEOUT', 30),
];
