<?php

namespace App\Models;

use App\Enums\PrinterConnection;
use App\Enums\PrinterStatus;
use App\Enums\PrinterType;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Unguarded]
class Printer extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Red: IP y puerto obligatorios. USB: ruta del dispositivo obligatoria.
        static::saving(function (Printer $printer) {
            $errors = [];
            if ($printer->isUsb()) {
                if (blank($printer->device_path)) {
                    $errors['device_path'] = 'Indica el dispositivo USB (p. ej. /dev/impresora-ticket).';
                } elseif (! str_starts_with($printer->device_path, '/')) {
                    $errors['device_path'] = 'El dispositivo debe ser una ruta absoluta, p. ej. /dev/usb/lp0.';
                }
            } else {
                if (blank($printer->host)) {
                    $errors['host'] = 'Indica la dirección IP de la impresora.';
                }
                if (! $printer->port) {
                    $errors['port'] = 'Indica el puerto (normalmente 9100).';
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        });
    }

    /**
     * Tipo de conexión. OJO: dentro del modelo `$this->connection` es la
     * propiedad de Eloquent con el nombre de la conexión a la BD, no la
     * columna; por eso se lee el atributo explícitamente.
     */
    public function connectionType(): PrinterConnection
    {
        $value = $this->getAttribute('connection');

        return $value instanceof PrinterConnection ? $value : (PrinterConnection::tryFrom((string) $value) ?? PrinterConnection::Network);
    }

    public function isUsb(): bool
    {
        return $this->connectionType() === PrinterConnection::Usb;
    }

    /** "192.168.1.200:9100" o "/dev/impresora-ticket". */
    public function address(): string
    {
        return $this->isUsb() ? (string) $this->device_path : "{$this->host}:{$this->port}";
    }

    protected function casts(): array
    {
        return [
            'type' => PrinterType::class,
            'connection' => PrinterConnection::class,
            'status' => PrinterStatus::class,
            'last_status_at' => 'datetime',
            'is_active' => 'boolean',
            'port' => 'integer',
            'columns' => 'integer',
            'codepage' => 'integer',
        ];
    }
}
