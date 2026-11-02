<?php

namespace Tests\Feature\Pos;

use App\Enums\PrinterStatus;
use App\Enums\PrintJobStatus;
use App\Events\Pos\PrinterStatusChanged;
use App\Events\Pos\PrintJobUpdated;
use App\Jobs\ProcessPrintJob;
use App\Models\PrintJob;
use App\Services\Printing\PrinterManager;
use App\Services\Printing\PrintWriteException;
use App\Services\Printing\Ticket;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class PrintFailureTest extends PosTestCase
{
    private function sendOneToKitchen(): PrintJob
    {
        $order = $this->openTable();
        $this->addLine($order['id'], ['product_id' => $this->product('Bravas', 550)->id]);
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/send")->assertOk();

        return PrintJob::query()->where('printer_id', $this->kitchen->id)->firstOrFail();
    }

    public function test_print_jobs_go_to_the_printing_queue(): void
    {
        Queue::fake();
        $this->sendOneToKitchen();

        Queue::assertPushedOn('printing', ProcessPrintJob::class);
    }

    public function test_failed_job_marks_printer_and_is_listed_and_never_reprinted_automatically(): void
    {
        Queue::fake();
        Event::fake([PrinterStatusChanged::class, PrintJobUpdated::class]);
        $job = $this->sendOneToKitchen();

        (new ProcessPrintJob($job->id))->failed(new RuntimeException('Connection refused'));

        $this->assertSame(PrintJobStatus::Failed, $job->fresh()->status);
        $this->assertSame(PrinterStatus::Error, $this->kitchen->fresh()->status);
        Event::assertDispatched(PrinterStatusChanged::class, fn ($e) => $e->status === 'error');

        $failed = $this->asWaiter()->getJson('/api/print-jobs/failed')->assertOk()->json('data');
        $this->assertSame([$job->id], collect($failed)->pluck('id')->all());
        $this->assertSame('5', $failed[0]['table']);
        $this->assertSame('Cocina', $failed[0]['printer']['name']);

        // La impresora vuelve (otro envío sale bien): pasa a OK, pero el fallido sigue esperando.
        $order = $this->openTable();
        $this->addLine($order['id'], ['product_id' => $this->product('Tortilla', 350)->id]);
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/send");
        $other = PrintJob::query()->latest('id')->first();
        app()->call([new ProcessPrintJob($other->id), 'handle']);

        $this->assertSame(PrinterStatus::Ok, $this->kitchen->fresh()->status);
        $this->assertSame(PrintJobStatus::Failed, $job->fresh()->status);
    }

    public function test_retry_and_redirect_failed_job(): void
    {
        Queue::fake();
        $job = $this->sendOneToKitchen();
        (new ProcessPrintJob($job->id))->failed(new RuntimeException('Timeout'));

        $this->asWaiter()->postJson("/api/print-jobs/{$job->id}/retry")->assertOk()->assertJsonPath('data.status', 'pendiente');
        Queue::assertPushedOn('printing', ProcessPrintJob::class, fn ($q) => $q->printJobId === $job->id);

        (new ProcessPrintJob($job->id))->failed(new RuntimeException('Timeout'));
        $this->asWaiter()->postJson("/api/print-jobs/{$job->id}/redirect", ['printer_id' => $this->bar->id])
            ->assertOk()->assertJsonPath('data.printer.name', 'Barra');

        $job->refresh();
        $this->assertSame($this->kitchen->id, $job->original_printer_id);
        $this->assertStringContainsString('REENVIADA DESDE COCINA', collect($job->content)->pluck('text')->implode("\n"));

        app()->call([new ProcessPrintJob($job->id), 'handle']);
        $this->assertSame(PrintJobStatus::Printed, $job->fresh()->status);
    }

    public function test_only_failed_jobs_can_be_retried(): void
    {
        Queue::fake();
        $job = $this->sendOneToKitchen();

        $this->asWaiter()->postJson("/api/print-jobs/{$job->id}/retry")->assertUnprocessable();
    }

    public function test_order_shows_print_status_per_send(): void
    {
        Queue::fake();
        $job = $this->sendOneToKitchen();
        (new ProcessPrintJob($job->id))->failed(new RuntimeException('Sin papel'));

        $this->asWaiter()->getJson("/api/orders/{$job->order_id}")->assertOk()
            ->assertJsonPath('data.sends.0.number', 1)
            ->assertJsonPath('data.print_jobs.0.status', 'fallido')
            ->assertJsonPath('data.print_jobs.0.error', 'Sin papel');
    }

    public function test_half_printed_ticket_is_not_retried_automatically(): void
    {
        Queue::fake();
        $job = $this->sendOneToKitchen();
        $this->mock(PrinterManager::class, function ($mock) {
            $mock->shouldReceive('forPrinter')->andReturnSelf();
            $mock->shouldReceive('print')->once()->andThrow(new PrintWriteException('Impresora USB sin respuesta en /dev/usb/x a mitad del ticket', 16_384));
        });

        app()->call([new ProcessPrintJob($job->id), 'handle']);   // no lanza: no habrá reintento

        $job->refresh();
        $this->assertSame(PrintJobStatus::Failed, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertStringContainsString('a mitad del ticket', $job->error);
    }

    public function test_nothing_sent_is_retried(): void
    {
        Queue::fake();
        $job = $this->sendOneToKitchen();
        $this->mock(PrinterManager::class, function ($mock) {
            $mock->shouldReceive('forPrinter')->andReturnSelf();
            $mock->shouldReceive('print')->andThrow(new PrintWriteException('Impresora no conectada: no existe el dispositivo /dev/usb/x.'));
        });

        try {
            app()->call([new ProcessPrintJob($job->id), 'handle']);
            $this->fail('Tenía que lanzar la excepción para que la cola lo reintente');
        } catch (PrintWriteException) {
            $this->assertSame(PrintJobStatus::Pending, $job->fresh()->status);   // la cola lo reintentará (tries = 3)
        }
    }

    public function test_tax_qr_leaves_no_temporary_files(): void
    {
        $before = glob(sys_get_temp_dir().'/qr*') ?: [];
        $ticket = (new Ticket)->taxQr('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345674&numserie=T-26-000001&fecha=02-11-2026&importe=10.00')->cut();
        (new PrinterManager(['mode' => 'simulated'] + config('printing')))->print($ticket);

        $this->assertSame($before, glob(sys_get_temp_dir().'/qr*') ?: []);
    }
}
