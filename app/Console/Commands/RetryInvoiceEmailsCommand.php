<?php

namespace App\Console\Commands;

use App\Jobs\SendInvoiceEmail;
use App\Models\InvoiceEmail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tpv:invoices-retry-email')]
#[Description('Reintenta los envíos de facturas por email que fallaron (p. ej. sin internet)')]
class RetryInvoiceEmailsCommand extends Command
{
    public function handle(): int
    {
        $failed = InvoiceEmail::query()->where('status', InvoiceEmail::ERROR)
            ->where('created_at', '>=', now()->subDays(30))->where('attempts', '<', 50)->get();
        foreach ($failed as $email) {
            $email->update(['status' => InvoiceEmail::PENDING]);
            SendInvoiceEmail::dispatch($email->id)->onQueue('email');
        }
        $this->line("Reintentados: {$failed->count()}");

        return self::SUCCESS;
    }
}
