<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Dos camareros unen la MISMA mesa libre a dos mesas distintas a la vez, cada
 * uno en su proceso y su conexión: el bloqueo de las mesas deja unir solo a uno.
 */
class ConcurrentJoinTablesTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_two_simultaneous_joins_of_the_same_table(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Necesita la extensión pcntl.');
        }

        Setting::factory()->create();
        [$t3, $t4, $t5] = Table::factory()->count(3)->create()->all();
        $users = User::factory()->count(2)->withPin('1234')->create();
        $orders = [
            app(OrderService::class)->open(OrderType::Table, $users[0], $t3, 2),
            app(OrderService::class)->open(OrderType::Table, $users[1], $t4, 2),
        ];
        DB::disconnect();

        $start = microtime(true) + 0.5;
        $pids = [];
        foreach ($orders as $i => $order) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::reconnect();
                time_sleep_until($start);
                try {
                    app(OrderService::class)->joinTables($order, [$t5->id], $users[$i]);
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
        $this->assertSame([0, 1], $codes, 'Uno une la mesa y el otro recibe «ya está unida».');
        $this->assertSame(1, OrderTable::query()->where('table_id', $t5->id)->whereNull('left_at')->count());
        $this->assertSame(TableStatus::Occupied, $t5->fresh()->status);
        $this->assertSame(2, Order::query()->where('status', 'abierto')->count());
    }
}
