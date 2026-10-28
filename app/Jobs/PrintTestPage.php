<?php

namespace App\Jobs;

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

    public function handle(PrinterManager $printer): array
    {
        $result = $printer->print(self::ticket());

        Log::info('Página de prueba impresa', $result);

        return $result;
    }

    public static function ticket(): Ticket
    {
        return (new Ticket)
            ->title(config('app.name'))
            ->line('Página de prueba', Ticket::ALIGN_CENTER)
            ->separator()
            ->line('Acentos: á é í ó ú Á É Í Ó Ú ü')
            ->line('Eñes: ñ Ñ  España, año, piña')
            ->line('Euro: 12,50 €', bold: true)
            ->line('Fecha: '.now()->format('d/m/Y H:i:s'))
            ->line('Modo: '.config('printing.mode').'  Tabla: '.(config('printing.codepage') ?: 'auto'))
            ->separator()
            ->line('Si lees bien esta línea, todo OK', Ticket::ALIGN_CENTER)
            ->feed(2)
            ->cut();
    }
}
