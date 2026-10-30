<?php

namespace App\Services\Billing;

use App\Jobs\SendInvoiceEmail;
use App\Mail\InvoiceMail;
use App\Models\Customer;
use App\Models\InvoiceEmail;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use LogicException;

/**
 * Envío de facturas por email con el SMTP de Ajustes. Solo con el
 * consentimiento del destinatario (art. 9.2 RD 1619/2012). Va por la cola
 * "email" con reintentos: sin internet, se reintenta hasta que sale.
 */
class InvoiceMailer
{
    public const MAILER = 'invoices';

    public static function configured(): bool
    {
        $s = Setting::query()->first();

        return $s !== null && filled($s->smtp_host) && filled($s->mail_from_address);
    }

    /** Encola el envío. $to: por defecto, el email de facturación del cliente. */
    public function queue(Ticket $invoice, ?User $by = null, ?string $to = null): InvoiceEmail
    {
        if (! $invoice->type->isFullInvoice()) {
            throw new LogicException('Solo se envían por email las facturas completas.');
        }
        $customer = $invoice->customer_id ? Customer::query()->find($invoice->customer_id) : null;
        if (! $customer || ! $customer->email_invoices_accepted_at) {
            throw new LogicException('El cliente no ha aceptado recibir las facturas por email (art. 9.2): imprímela o descárgala.');
        }
        $to ??= $customer->billing_email ?: throw new LogicException('El cliente no tiene email para facturas.');
        if (! self::configured()) {
            throw new LogicException('Configura el correo saliente (SMTP) en Ajustes del local.');
        }

        $email = InvoiceEmail::create(['ticket_id' => $invoice->id, 'to' => $to, 'status' => InvoiceEmail::PENDING, 'requested_by' => $by?->id]);
        SendInvoiceEmail::dispatch($email->id)->onQueue('email')->afterCommit();

        return $email;
    }

    /** Al emitir una factura: si el cliente lo aceptó y hay SMTP, se envía sola. */
    public function queueIfAccepted(Ticket $invoice, ?User $by = null): ?InvoiceEmail
    {
        $customer = $invoice->customer_id ? Customer::query()->find($invoice->customer_id) : null;
        if (! $invoice->type->isFullInvoice() || ! $customer?->acceptsEmailInvoices() || ! self::configured()) {
            return null;
        }

        return $this->queue($invoice, $by);
    }

    /** Envío real (lo llama el job). */
    public function send(InvoiceEmail $email): void
    {
        self::configureMailer();
        Mail::mailer(self::MAILER)->to($email->to)->send(new InvoiceMail($email->ticket));
    }

    /** Mailer "invoices" con los datos SMTP de Ajustes (en cada envío: pueden cambiar). */
    public static function configureMailer(): void
    {
        $s = Setting::current();
        config([
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                // ssl = TLS implícito (465, smtps); tls = STARTTLS; none = sin cifrar.
                'scheme' => $s->smtp_encryption === 'ssl' ? 'smtps' : 'smtp',
                'host' => $s->smtp_host,
                'port' => $s->smtp_port ?: ($s->smtp_encryption === 'ssl' ? 465 : 587),
                'username' => $s->smtp_username,
                'password' => $s->smtp_password,
                'timeout' => 20,
                'auto_tls' => $s->smtp_encryption !== 'none',
            ],
            'mail.from' => ['address' => $s->mail_from_address, 'name' => $s->mail_from_name ?: $s->trade_name],
        ]);
        Mail::purge(self::MAILER);
    }
}
