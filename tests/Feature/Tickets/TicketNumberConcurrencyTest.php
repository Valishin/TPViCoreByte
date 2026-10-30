<?php

namespace Tests\Feature\Tickets;

use App\Models\TicketSeries;
use App\Services\Tickets\TicketNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dos procesos (cada uno con su conexión a PostgreSQL) piden números de la
 * misma serie a la vez: el bloqueo del contador los serializa y salen todos,
 * sin huecos ni repetidos. Necesita datos confirmados (los otros procesos no
 * ven la transacción del test): no se envuelve en transacción y se limpia al
 * terminar.
 */
class TicketNumberConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [];

    private ?TicketSeries $series = null;

    protected function tearDown(): void
    {
        if ($this->series) {
            DB::table('ticket_series_counters')->where('ticket_series_id', $this->series->id)->delete();
            $this->series->delete();
        }
        parent::tearDown();
    }

    public function test_concurrent_processes_get_unique_correlative_numbers(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Sin pcntl.');
        }
        $this->series = TicketSeries::factory()->create(['code' => 'CONC']);
        $perProcess = 25;
        $files = [];

        DB::disconnect(); // cada proceso abre su propia conexión
        $pids = [];
        foreach (range(1, 2) as $i) {
            $files[$i] = tempnam(sys_get_temp_dir(), 'num');
            $pid = pcntl_fork();
            if ($pid === 0) {
                $numbers = [];
                foreach (range(1, $perProcess) as $_) {
                    $numbers[] = DB::transaction(function () {
                        $n = app(TicketNumberService::class)->next($this->series);
                        usleep(random_int(0, 2000)); // la fila sigue bloqueada mientras "se emite"

                        return $n->number;
                    });
                }
                file_put_contents($files[$i], json_encode($numbers));
                posix_kill(getmypid(), SIGKILL); // sin destructores ni shutdown de PHPUnit
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $all = array_merge(...array_map(fn ($f) => json_decode((string) file_get_contents($f), true) ?? [], $files));
        array_map('unlink', $files);
        sort($all);

        $this->assertSame(range(1, 2 * $perProcess), $all);
        $this->assertSame(2 * $perProcess, $this->series->lastNumber());
    }
}
