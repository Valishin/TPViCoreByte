<?php

namespace App\Services\Printing;

use App\Models\Printer as PrinterModel;
use App\Services\Verifactu\TaxQr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\PrintConnector;
use Mike42\Escpos\Printer;

/**
 * Envía tickets a la impresora según PRINTER_MODE (real | simulated).
 */
class PrinterManager
{
    public function __construct(private array $config) {}

    /**
     * Copia configurada para una impresora concreta (host, puerto, columnas,
     * página de códigos, perfil). El modo (simulated / network) sigue siendo
     * el global de PRINTER_MODE.
     */
    public function forPrinter(PrinterModel $printer): self
    {
        $config = $this->config;
        $config['connection'] = $printer->connection_type->value;
        $config['usb']['device_path'] = $printer->device_path ?? $config['usb']['device_path'];
        $config['network']['host'] = $printer->host ?? $config['network']['host'];
        $config['network']['port'] = $printer->port ?: $config['network']['port'];
        $config['columns'] = $printer->columns ?: $config['columns'];
        $config['codepage'] = $printer->codepage;
        $config['profile'] = $printer->profile ?: $config['profile'];

        return new self($config);
    }

    /** Valores admitidos de PRINTER_MODE. "network" es un alias antiguo de "real". */
    public const MODE_REAL = 'real';

    public const MODE_SIMULATED = 'simulated';

    public function mode(): string
    {
        $mode = $this->config['mode'];
        if ($mode === 'network') {
            // Un aviso por proceso/aplicación (no en cada impresión).
            if (! app()->bound('printing.alias-warned')) {
                app()->instance('printing.alias-warned', true);
                Log::warning('PRINTER_MODE=network está obsoleto: usa PRINTER_MODE=real.');
            }

            return self::MODE_REAL;
        }

        return $mode;
    }

    /**
     * Imprime el ticket y devuelve información del resultado.
     *
     * @return array{mode: string, bytes: int, file?: string, target?: string}
     */
    public function print(Ticket $ticket): array
    {
        return match ($this->mode()) {
            self::MODE_REAL => $this->isUsb() ? $this->printToUsb($ticket) : $this->printToNetwork($ticket),
            self::MODE_SIMULATED => $this->printSimulated($ticket),
            default => throw new InvalidArgumentException("PRINTER_MODE no válido: {$this->mode()} (usa real o simulated)."),
        };
    }

    private function printToNetwork(Ticket $ticket): array
    {
        ['host' => $host, 'port' => $port, 'timeout' => $timeout] = $this->config['network'];

        $dummy = new DummyPrintConnector;
        $this->renderEscpos($ticket, $dummy);
        $bytes = $dummy->getData();
        $dummy->finalize();

        // Se genera todo en memoria y se envía de golpe: si la impresora no
        // responde, falla antes de dejar un ticket a medias.
        $connector = new NetworkPrintConnector($host, $port, $timeout);
        $connector->write($bytes);
        $connector->finalize();

        return ['mode' => self::MODE_REAL, 'bytes' => strlen($bytes), 'target' => "{$host}:{$port}"];
    }

    public function isUsb(): bool
    {
        return ($this->config['connection'] ?? 'red') === 'usb';
    }

    /**
     * Impresora USB: se escribe en el dispositivo (/dev/usb/lp0 o un enlace
     * fijo creado con udev, ver docs/impresoras-usb.md), con límite de tiempo
     * con o sin pcntl (UsbDeviceWriter).
     */
    private function printToUsb(Ticket $ticket): array
    {
        $path = (string) $this->config['usb']['device_path'];

        $dummy = new DummyPrintConnector;
        $this->renderEscpos($ticket, $dummy);
        $bytes = $dummy->getData();
        $dummy->finalize();

        app(UsbDeviceWriter::class)->write($path, $bytes,
            (int) ($this->config['usb']['idle_timeout'] ?? $this->config['network']['timeout']),
            (int) ($this->config['usb']['max_seconds'] ?? 15));

        return ['mode' => self::MODE_REAL, 'bytes' => strlen($bytes), 'target' => $path];
    }

    public function target(): string
    {
        if ($this->mode() === self::MODE_REAL && $this->isUsb()) {
            return (string) $this->config['usb']['device_path'];
        }

        return $this->mode() === self::MODE_REAL
            ? $this->config['network']['host'].':'.$this->config['network']['port']
            : 'storage/app/private/'.trim($this->config['simulated_path'], '/');
    }

    private function printSimulated(Ticket $ticket): array
    {
        $dummy = new DummyPrintConnector;
        $this->renderEscpos($ticket, $dummy);
        $bytes = $dummy->getData();
        $dummy->finalize();

        $disk = Storage::disk('local');
        $base = trim($this->config['simulated_path'], '/').'/'.now()->format('Ymd-His-v');

        $disk->put("{$base}.txt", $this->renderText($ticket));
        $disk->put("{$base}.bin", $bytes);

        return ['mode' => 'simulated', 'bytes' => strlen($bytes), 'file' => $disk->path("{$base}.txt")];
    }

    /** Traduce el ticket a comandos ESC/POS. */
    private function renderEscpos(Ticket $ticket, PrintConnector $connector): void
    {
        $profile = CapabilityProfile::load($this->config['profile']);
        $printer = new Printer($connector, $profile);
        $codepage = $this->config['codepage'];
        $encoding = null;

        if ($codepage !== null && $codepage !== '') {
            $pages = $profile->getCodePages();
            if (! isset($pages[(int) $codepage])) {
                throw new InvalidArgumentException("PRINTER_CODEPAGE={$codepage} no existe en el perfil {$profile->getId()}");
            }
            $encoding = $pages[(int) $codepage]->getIconv() ?? $pages[(int) $codepage]->getName();
            $printer->selectCharacterTable((int) $codepage);
        }

        $write = function (string $text) use ($printer, $encoding): void {
            if ($encoding === null) {
                $printer->text($text);

                return;
            }
            $converted = iconv('UTF-8', $encoding.'//TRANSLIT', $text);
            $printer->textRaw($converted === false ? $text : $converted);
        };

        foreach ($ticket->operations() as $op) {
            switch ($op['type']) {
                case 'line':
                    $printer->setJustification(match ($op['align']) {
                        Ticket::ALIGN_CENTER => Printer::JUSTIFY_CENTER,
                        Ticket::ALIGN_RIGHT => Printer::JUSTIFY_RIGHT,
                        default => Printer::JUSTIFY_LEFT,
                    });
                    $printer->setEmphasis($op['bold']);
                    $printer->setReverseColors($op['reverse'] ?? false);
                    $op['large'] ? $printer->setTextSize(2, 2) : $printer->setTextSize(1, 1);
                    $write($op['text']."\n");
                    $printer->setTextSize(1, 1);
                    $printer->setReverseColors(false);
                    $printer->setEmphasis(false);
                    $printer->setJustification(Printer::JUSTIFY_LEFT);
                    break;
                case 'columns':
                    $printer->setEmphasis($op['bold']);
                    $write(self::fitColumns($op['left'], $op['right'], $this->config['columns'])."\n");
                    $printer->setEmphasis(false);
                    break;
                case 'separator':
                    $write(str_repeat($op['char'], $this->config['columns'])."\n");
                    break;
                case 'feed':
                    $printer->feed($op['lines']);
                    break;
                case 'spacing':
                    $printer->setLineSpacing($op['dots']);
                    break;
                case 'cut':
                    $printer->cut();
                    break;
                case 'pulse':
                    $printer->pulse();
                    break;
                case 'qr':
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->qrCode($op['data'], Printer::QR_ECLEVEL_M, $op['size'] ?? 6);
                    $printer->setJustification(Printer::JUSTIFY_LEFT);
                    break;
                case 'tax_qr':
                    // Ráster a 203 ppp (8 puntos/mm): el tamaño en papel no depende del modelo de impresora.
                    // tempnam() ya crea un fichero vacío: se borran los dos (antes quedaba el vacío).
                    $base = tempnam(sys_get_temp_dir(), 'qr');
                    $file = $base.'.png';
                    try {
                        file_put_contents($file, TaxQr::png($op['data'], (int) config('printing.dots_per_mm', 8)));
                        $printer->setJustification(Printer::JUSTIFY_CENTER);
                        $printer->bitImage(EscposImage::load($file, false));
                        $printer->setJustification(Printer::JUSTIFY_LEFT);
                    } finally {
                        @unlink($file);
                        @unlink($base);
                    }
                    break;
            }
        }
        // Sin $printer->close(): finalizaría el conector y perderíamos los
        // bytes. Quien llama lee getData() y luego finaliza.
    }

    /** Representación legible del ticket para el modo simulado. */
    private function renderText(Ticket $ticket): string
    {
        $columns = $this->config['columns'];
        $out = [];

        foreach ($ticket->operations() as $op) {
            switch ($op['type']) {
                case 'line':
                    $text = $op['large'] ? mb_strtoupper($op['text']) : $op['text'];
                    $text = $op['bold'] ? "**{$text}**" : $text;
                    $text = ($op['reverse'] ?? false) ? "█ {$text} █" : $text;
                    $pad = max(0, $columns - mb_strlen($text));
                    $out[] = match ($op['align']) {
                        Ticket::ALIGN_CENTER => str_repeat(' ', intdiv($pad, 2)).$text,
                        Ticket::ALIGN_RIGHT => str_repeat(' ', $pad).$text,
                        default => $text,
                    };
                    break;
                case 'columns':
                    $out[] = self::fitColumns($op['left'], $op['right'], $columns);
                    break;
                case 'separator':
                    $out[] = str_repeat($op['char'], $columns);
                    break;
                case 'feed':
                    array_push($out, ...array_fill(0, $op['lines'], ''));
                    break;
                case 'cut':
                    $out[] = str_repeat('✂ ', intdiv($columns, 2)).' [CORTE]';
                    break;
                case 'pulse':
                    $out[] = '[ABRIR CAJÓN]';
                    break;
                case 'spacing':
                    break; // el interlineado solo cuenta en papel
                case 'qr':
                    $out[] = "[QR] {$op['data']}";
                    break;
                case 'tax_qr':
                    $out[] = '[QR tributario '.TaxQr::sizing($op['data'])['mm']." mm] {$op['data']}";
                    break;
            }
        }

        return implode("\n", $out)."\n";
    }

    /** "Croquetas ........ 7,50 €" ajustado al ancho del papel. */
    private static function fitColumns(string $left, string $right, int $columns): string
    {
        $space = $columns - mb_strlen($right) - 1;
        $left = mb_strlen($left) > $space ? mb_substr($left, 0, max(0, $space)) : $left;

        return $left.str_repeat(' ', max(1, $columns - mb_strlen($left) - mb_strlen($right))).$right;
    }
}
