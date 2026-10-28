<?php

namespace App\Jobs;

use App\Models\Printer;
use App\Services\Printing\PrinterManager;
use App\Services\Printing\Ticket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PrintTestPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /** @param  int|null  $printerId  impresora concreta; null = la configuración global del .env */
    public function __construct(public ?int $printerId = null) {}

    public function handle(PrinterManager $printer): array
    {
        $model = $this->printerId ? Printer::find($this->printerId) : null;
        if ($model) {
            $printer = $printer->forPrinter($model);
        }

        $result = $printer->print(self::ticket($model)) + ['printer' => $model?->name];

        Log::info('Página de prueba impresa', $result);

        return $result;
    }

    public static function ticket(?Printer $printer = null): Ticket
    {
        return (new Ticket)
            ->title(config('app.name'))
            ->line('Página de prueba', Ticket::ALIGN_CENTER)
            ->line($printer ? "Impresora: {$printer->name}" : 'Impresora: configuración global', Ticket::ALIGN_CENTER)
            ->separator()
            ->line('Acentos: á é í ó ú Á É Í Ó Ú ü')
            ->line('Eñes: ñ Ñ  España, año, piña')
            ->line('Euro: 12,50 €', bold: true)
            ->line('Fecha: '.now()->format('d/m/Y H:i:s'))
            ->line('Modo: '.config('printing.mode').'  Tabla: '.(($printer ? $printer->codepage : config('printing.codepage')) ?: 'auto'))
            ->separator()
            ->line('Si lees bien esta línea, todo OK', Ticket::ALIGN_CENTER)
            ->feed(2)
            ->cut();
    }
}
