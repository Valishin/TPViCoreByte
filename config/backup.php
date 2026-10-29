<?php

return [
    // Directorio de las copias cifradas (volumen "backups" en producción).
    'path' => env('BACKUP_PATH', '/backups'),

    // Clave PÚBLICA age (age1…). La privada se guarda fuera del mini PC.
    'age_recipient' => env('BACKUP_AGE_RECIPIENT'),

    // Destino rclone (vacío = solo local), p. ej. ":s3,provider=Minio,endpoint=http://…:bucket/tpv"
    // o ":sftp,host=copias.example.com,user=tpv:/srv/copias/local1".
    'remote' => env('BACKUP_REMOTE'),

    // Rotación: N diarias, N semanales (última de cada semana), N mensuales (última de cada mes).
    'keep' => [
        'daily' => (int) env('BACKUP_KEEP_DAILY', 7),
        'weekly' => (int) env('BACKUP_KEEP_WEEKLY', 4),
        'monthly' => (int) env('BACKUP_KEEP_MONTHLY', 6),
    ],

    // Aviso en /admin si la última copia remota es más antigua.
    'remote_max_age_hours' => (int) env('BACKUP_REMOTE_MAX_AGE_HOURS', 48),
];
