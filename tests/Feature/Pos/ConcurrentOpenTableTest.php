<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Dos camareros abren la MISMA mesa a la vez desde dos procesos con su propia
 * conexión: el bloqueo de fila (SELECT ... FOR UPDATE) deja abrir solo a uno.
 * Usa DatabaseTruncation porque con RefreshDatabase todo iría en una única
 * transacción y no habría concurrencia real.
 */
class ConcurrentOpenTableTest extends TestCase
{
    use DatabaseTruncation;

    /** Aquí los datos se confirman de verdad: se limpian para no contaminar otros tests. */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_two_simultaneous_opens_of_the_same_table(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Necesita la extensión pcntl.');
        }

        Setting::factory()->create();
        $table = Table::factory()->create();
        $users = User::factory()->count(2)->withPin('1234')->create();
        DB::disconnect(); // cada proceso abrirá su propia conexión

        $start = microtime(true) + 0.5; // los dos arrancan a la vez
        $pids = [];
        foreach ($users as $user) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::reconnect();
                time_sleep_until($start);
                try {
                    app(OrderService::class)->open(OrderType::Table, $user, $table, 2);
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }

        $codes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }
        DB::reconnect();

        sort($codes);
        $this->assertSame([0, 1], $codes, 'Uno debe abrir la mesa y el otro recibir "ya está abierta".');
        $this->assertSame(1, Order::query()->where('table_id', $table->id)->count());
        $this->assertSame(TableStatus::Occupied, $table->fresh()->status);
    }
}
