<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Services\Billing\InvoicePdf;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** La factura en PDF, para el destinatario. */
class InvoiceMail extends Mailable
{
    public function __construct(public Ticket $invoice) {}

    public function envelope(): Envelope
    {
        $issuer = $this->invoice->issuer['trade_name'] ?? $this->invoice->issuer['legal_name'] ?? '';
        $kind = $this->invoice->type->isRectifying() ? 'Factura rectificativa' : 'Factura';

        return new Envelope(subject: "{$kind} {$this->invoice->fullNumber()} · {$issuer}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invoice');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => app(InvoicePdf::class)->render($this->invoice), InvoicePdf::filename($this->invoice))
            ->withMime('application/pdf')];
    }
}
