<?php

namespace App\Services\Billing;

use App\Models\Setting;
use App\Models\Ticket;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use LogicException;

/** PDF A4 de una factura completa, con el logo del local (dompdf). */
class InvoicePdf
{
    public function render(Ticket $ticket): string
    {
        if (! $ticket->type->isFullInvoice()) {
            throw new LogicException('Solo las facturas completas tienen PDF A4.');
        }

        return Pdf::loadView('pdf.invoice', ['doc' => InvoiceDocument::make($ticket), 'logo' => self::logo()])
            ->setPaper('a4')
            // Solo los caracteres usados de la fuente: ~30 KB en vez de ~900 KB (va por email).
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    public static function filename(Ticket $ticket): string
    {
        return 'Factura-'.$ticket->fullNumber().'.pdf';
    }

    /** Logo como data URI (dompdf no lee rutas remotas). */
    private static function logo(): ?string
    {
        $path = Setting::query()->value('logo_path');
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return "data:{$mime};base64,".base64_encode(Storage::disk('public')->get($path));
    }
}
