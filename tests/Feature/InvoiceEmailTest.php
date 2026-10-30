<?php

namespace Tests\Feature;

use App\Jobs\SendInvoiceEmail;
use App\Mail\InvoiceMail;
use App\Models\InvoiceEmail;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Billing\InvoiceMailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pos\InvoiceTestCase;

class InvoiceEmailTest extends InvoiceTestCase
{
    private function smtp(): void
    {
        Setting::current()->update(['smtp_host' => 'smtp.ejemplo.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'smtp_username' => 'tpv', 'smtp_password' => 'secreta', 'mail_from_address' => 'facturas@bellanapoli.cat']);
    }

    private function invoice(): Ticket
    {
        $order = $this->orderWith([[$this->pizza, 1]]);
        $this->invoiceCheckout($order, 1000)->assertCreated();

        return Ticket::query()->where('order_id', $order)->sole();
    }

    public function test_smtp_password_is_encrypted_and_hidden(): void
    {
        $this->smtp();
        $raw = DB::table('settings')->value('smtp_password');
        $this->assertNotSame('secreta', $raw);
        $this->assertSame('secreta', Crypt::decryptString($raw));
        $this->assertArrayNotHasKey('smtp_password', Setting::current()->toArray());
    }

    public function test_invoice_is_emailed_automatically_when_customer_accepted(): void
    {
        Mail::fake();
        $this->smtp();
        $this->openCash();
        $invoice = $this->invoice();

        $email = InvoiceEmail::query()->sole(); // cola síncrona en los tests
        $this->assertSame('admin@port.cat', $email->to);
        $this->assertSame(InvoiceEmail::SENT, $email->status);
        Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $m) => $m->hasTo('admin@port.cat') && $m->invoice->is($invoice)
            && $m->mailer === 'invoices' && count($m->attachments()) === 1);
        $this->assertSame('enviado', $this->asWaiter()->getJson("/api/tickets/{$invoice->id}")->json('data.email.status'));
    }

    public function test_no_email_without_consent_or_without_smtp(): void
    {
        Mail::fake();
        $this->openCash();
        $this->invoice(); // sin SMTP
        $this->assertSame(0, InvoiceEmail::count());

        $this->smtp();
        $this->company->update(['email_invoices_accepted_at' => null]);
        $invoice = $this->invoice();
        $this->assertSame(0, InvoiceEmail::count());
        $this->asWaiter()->postJson("/api/tickets/{$invoice->id}/email")->assertStatus(422)
            ->assertJsonPath('message', 'El cliente no ha aceptado recibir las facturas por email (art. 9.2): imprímela o descárgala.');
        Mail::assertNothingSent();
    }

    public function test_queued_on_email_queue_with_retries_and_error_is_visible(): void
    {
        Queue::fake();
        $this->smtp();
        $this->openCash();
        $invoice = $this->invoice();
        Queue::assertPushedOn('email', SendInvoiceEmail::class);

        // Sin internet: el envío falla, queda en error con el motivo y se reintenta.
        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('Connection could not be established with host smtp.ejemplo.com'));
        Mail::shouldReceive('purge');
        $email = InvoiceEmail::query()->sole();
        try {
            (new SendInvoiceEmail($email->id))->handle(app(InvoiceMailer::class));
        } catch (\RuntimeException) {
        }
        $email->refresh();
        $this->assertSame(InvoiceEmail::ERROR, $email->status);
        $this->assertSame(1, $email->attempts);
        $this->assertStringContainsString('Connection could not be established', $email->last_error);
        $this->assertSame(8, (new SendInvoiceEmail($email->id))->tries);

        $this->artisan('tpv:invoices-retry-email')->assertSuccessful();
        $this->assertSame(InvoiceEmail::PENDING, $email->refresh()->status);
        Queue::assertPushedOn('email', SendInvoiceEmail::class, fn ($job) => $job->invoiceEmailId === $email->id);
    }

    public function test_tickets_are_never_emailed(): void
    {
        Mail::fake();
        $this->smtp();
        $this->openCash();
        $order = $this->orderWith([[$this->water, 1]]);
        $this->checkout($order, [['method' => 'efectivo', 'amount' => 150]]);
        $ticket = Ticket::query()->where('order_id', $order)->sole();

        $this->asWaiter()->postJson("/api/tickets/{$ticket->id}/email")->assertStatus(422)
            ->assertJsonPath('message', 'Solo se envían por email las facturas completas.');
    }
}
