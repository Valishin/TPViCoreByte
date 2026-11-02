<?php

namespace App\Services\Printing;

use App\Models\Printer;

/**
 * Impresoras USB conectadas, leídas de /sys (el contenedor ve el sysfs del
 * equipo). La regla udev genérica de install.sh crea para cada una el enlace
 * fijo /dev/usb/impresora-<fabricante>-<modelo>[-<serie>] con el mismo nombre
 * que calcula linkName(): no cambia aunque se reinicie o se enchufe en otro
 * puerto. El worker de impresión ve /dev/usb, también en caliente.
 */
class UsbPrinterDetector
{
    public function __construct(private ?string $sysfs = null)
    {
        $this->sysfs ??= (string) config('printing.sysfs', '/sys');
    }

    /** Nombre del enlace (igual que /usr/local/lib/tpv/impresora-usb-nombre del instalador). */
    public static function linkName(string $vendor, string $product, ?string $serial): string
    {
        $serial = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $serial));

        return 'impresora-'.strtolower($vendor).'-'.strtolower($product).($serial !== '' ? '-'.substr($serial, 0, 24) : '');
    }

    /** @return list<array{path: string, label: string, vendor: string, product: string, serial: ?string, kernel: string, configured: bool}> */
    public function detect(): array
    {
        $configured = Printer::query()->whereNotNull('device_path')->pluck('device_path')->all();
        $found = [];
        foreach (glob($this->sysfs.'/class/usbmisc/lp*') ?: [] as $entry) {
            $usb = $this->usbDevice($entry);
            if (! $usb) {
                continue;
            }
            $read = fn (string $f) => is_readable("{$usb}/{$f}") ? trim((string) file_get_contents("{$usb}/{$f}")) : null;
            $vendor = $read('idVendor');
            $product = $read('idProduct');
            if (! $vendor || ! $product) {
                continue;
            }
            $serial = $read('serial') ?: null;
            $path = '/dev/usb/'.self::linkName($vendor, $product, $serial);
            $found[] = [
                'path' => $path,
                'label' => trim(($read('manufacturer') ?? '').' '.($read('product') ?? '')) ?: "USB {$vendor}:{$product}",
                'vendor' => $vendor, 'product' => $product, 'serial' => $serial,
                'kernel' => basename($entry),
                'configured' => in_array($path, $configured, true),
            ];
        }
        usort($found, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $found;
    }

    /** Del lp* (interfaz) al dispositivo USB que tiene idVendor/idProduct (subiendo por /sys). */
    private function usbDevice(string $entry): ?string
    {
        $dir = realpath("{$entry}/device");
        for ($i = 0; $dir && $i < 4; $i++, $dir = dirname($dir)) {
            if (is_file("{$dir}/idVendor")) {
                return $dir;
            }
        }

        return null;
    }
}
