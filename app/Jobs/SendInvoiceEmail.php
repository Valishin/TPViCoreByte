<?php

namespace App\Jobs;

use App\Models\InvoiceEmail;
use App\Services\Billing\InvoiceMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Envía una factura por email (cola "email"). Sin internet, reintenta con esperas crecientes. */
class SendInvoiceEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 60;

    public function __construct(public int $invoiceEmailId) {}

    /** 1 min, 5 min, 15 min, 30 min, 1 h, 2 h, 4 h (después, la tarea horaria). */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 7200, 14400];
    }

    public function handle(InvoiceMailer $mailer): void
    {
        $email = InvoiceEmail::query()->find($this->invoiceEmailId);
        if (! $email || $email->status === InvoiceEmail::SENT) {
            return;
        }
        $email->increment('attempts');
        try {
            $mailer->send($email);
        } catch (Throwable $e) {
            $email->update(['status' => InvoiceEmail::ERROR, 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            throw $e; // la cola reintenta
        }
        $email->update(['status' => InvoiceEmail::SENT, 'sent_at' => now(), 'last_error' => null]);
    }
}
