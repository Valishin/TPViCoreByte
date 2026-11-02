<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Cash\CashSessionService;
use App\Support\BackupStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tpv-backups-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['backup.path' => $this->dir, 'backup.age_recipient' => 'age1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq', 'backup.remote' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** Simula pg_dump | age escribiendo el fichero de salida. */
    private function fakeDump(bool $ok = true, bool $uploadOk = true): void
    {
        Process::fake(function (PendingProcess $process) use ($ok, $uploadOk) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command; // bash -c '…'
            if (str_contains($command, 'pg_dump')) {
                if ($ok && preg_match("/-o '([^']+)'/", $command, $m)) {
                    file_put_contents($m[1], 'cifrado');
                }

                return $ok ? Process::result('') : Process::result('', 'pg_dump: error: connection refused', 1);
            }
            // Claves (age -r … -o fichero) y archivos (tar | age … -o 'fichero'): también cifrados.
            if (! str_contains($command, 'rclone') && (preg_match("/-o '([^']+)'/", $command, $m) || preg_match('/-o (\S+)/', $command, $m))) {
                file_put_contents($m[1], 'cifrado');

                return Process::result('');
            }

            return $uploadOk ? Process::result('') : Process::result('', 'rclone: no network', 1);
        });
    }

    public function test_dump_is_piped_through_age_with_the_public_key(): void
    {
        $this->fakeDump();

        $run = app(BackupService::class)->run('manual');

        $this->assertSame('ok', $run->status);
        $this->assertSame('sin_destino', $run->remote_status);
        $this->assertFileExists("{$this->dir}/{$run->file}");
        $this->assertMatchesRegularExpression(BackupService::PATTERN, $run->file);
        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'bash' && str_contains($p->command[2], 'set -o pipefail; pg_dump')
            && str_contains($p->command[2], '| age -r ') && str_contains($p->command[2], '-Fc') && ($p->environment['PGPASSWORD'] ?? null) !== null);
    }

    public function test_refuses_to_store_unencrypted_backups(): void
    {
        config(['backup.age_recipient' => null]);
        $this->fakeDump();

        $run = app(BackupService::class)->run('diaria');

        $this->assertSame('error', $run->status);
        $this->assertStringContainsString('clave pública age', $run->error);
        Process::assertNothingRan();
    }

    public function test_failed_dump_is_recorded_and_leaves_no_file(): void
    {
        $this->fakeDump(ok: false);

        $run = app(BackupService::class)->run('cierre_z');

        $this->assertSame('error', $run->status);
        $this->assertStringContainsString('connection refused', $run->error);
        $this->assertSame([], File::files($this->dir));
    }

    public function test_upload_with_rclone_and_retry_when_offline(): void
    {
        config(['backup.remote' => ':s3,provider=Minio,endpoint=http://minio:9000:tpv']);
        $this->fakeDump(uploadOk: false);
        $run = app(BackupService::class)->run('diaria');
        $this->assertSame('error', $run->remote_status);

        $this->fakeDump(uploadOk: true);
        $this->assertTrue(app(BackupService::class)->upload());
        $this->assertSame('subida', $run->refresh()->remote_status);
        Process::assertRan(fn (PendingProcess $p) => is_array($p->command) && $p->command[0] === 'rclone' && $p->command[1] === 'sync');
    }

    public function test_gfs_rotation_keeps_7_daily_4_weekly_6_monthly(): void
    {
        // Una copia diaria durante 200 días, más dos el último día.
        $end = Carbon::parse('2026-11-04 05:00:00');
        foreach (range(0, 199) as $d) {
            touch("{$this->dir}/tpv-".$end->copy()->subDays($d)->format('Ymd-His').'-diaria.dump.age');
        }
        touch("{$this->dir}/tpv-20261104-230000-cierre_z.dump.age");
        touch("{$this->dir}/otro-fichero.txt"); // no se toca

        app(BackupService::class)->rotate();

        $left = collect(File::files($this->dir))->map->getFilename()->sort()->values();
        $this->assertContains('otro-fichero.txt', $left->all());
        $backups = $left->reject(fn ($f) => $f === 'otro-fichero.txt');
        // 7 días (el último día: la más reciente, la del Z) + semanas y meses anteriores sin repetir.
        $this->assertContains('tpv-20261104-230000-cierre_z.dump.age', $backups->all());
        $this->assertNotContains('tpv-20261104-050000-diaria.dump.age', $backups->all());
        $this->assertLessThanOrEqual(7 + 4 + 6, $backups->count());
        $this->assertGreaterThanOrEqual(12, $backups->count());
        // La más antigua que queda es de hace unos 6 meses.
        $this->assertStringStartsWith('tpv-2026', $backups->first());
        $this->assertGreaterThanOrEqual('tpv-20260501', $backups->first());
    }

    public function test_backup_runs_after_closing_z(): void
    {
        $this->fakeDump();
        $this->createSettings();
        $manager = User::factory()->role(UserRole::Manager)->withPin('9999')->create();
        $session = app(CashSessionService::class)->open($manager, 10000);

        app(CashSessionService::class)->close($session, $manager, 10000);

        $this->assertSame('cierre_z', BackupRun::query()->sole()->reason); // cola síncrona en los tests
    }

    public function test_boot_backup_only_if_last_is_older_than_24h(): void
    {
        $this->fakeDump();
        BackupRun::create(['reason' => 'diaria', 'file' => 'x', 'status' => 'ok', 'remote_status' => 'sin_destino', 'created_at' => now()->subHours(3)]);

        $this->artisan('tpv:backup --reason=arranque --if-older=24')->assertSuccessful();
        $this->assertSame(1, BackupRun::count());

        $this->travel(22)->hours();
        $this->artisan('tpv:backup --reason=arranque --if-older=24')->assertSuccessful();
        $this->assertSame('arranque', BackupRun::query()->latest('id')->first()->reason);
    }

    public function test_admin_warning_when_remote_copy_is_older_than_48h(): void
    {
        config(['backup.remote' => ':sftp,host=x:/copias']);
        $this->assertSame('Todavía no se ha subido ninguna copia de seguridad al destino remoto.', BackupStatus::warning());

        BackupRun::create(['reason' => 'diaria', 'file' => 'x', 'status' => 'ok', 'remote_status' => 'subida', 'remote_at' => now()->subHours(50)]);
        $this->assertStringContainsString('más de 48 h', BackupStatus::warning());

        BackupRun::create(['reason' => 'diaria', 'file' => 'y', 'status' => 'ok', 'remote_status' => 'subida', 'remote_at' => now()->subHours(2)]);
        $this->assertNull(BackupStatus::warning());

        $this->createSettings();
        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
        BackupRun::query()->update(['remote_at' => now()->subDays(3)]);
        $this->get('/admin')->assertOk()->assertSee('más de 48 h');
    }
}
