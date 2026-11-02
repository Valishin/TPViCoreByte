<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\BackupSettings;
use App\Models\BackupRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\Backup\BackupDisk;
use App\Services\Backup\BackupService;
use App\Support\BackupStatus;
use App\Support\SetupProgress;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Copia en un disco aparte (USB): en cada copia (cierre Z…) va al disco todo lo
 * necesario —base de datos, claves y archivos, cifrado— y, si no está
 * conectado, se copia en cuanto se conecte.
 */
class BackupDiskTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '1A2B-3C4D';

    private string $dir;

    private string $disks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tpv-backups-'.uniqid();
        $this->disks = sys_get_temp_dir().'/tpv-discos-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::ensureDirectoryExists("{$this->disks}/.info");
        // Lo que deja el equipo (tpv-disco) al conectar el disco.
        File::put("{$this->disks}/.info/".self::UUID.'.json', json_encode(['uuid' => self::UUID, 'label' => 'COPIAS', 'fstype' => 'exfat', 'size' => 1_000_000_000_000]));
        $this->connect();
        config(['backup.path' => $this->dir, 'backup.disks_path' => $this->disks, 'backup.remote' => null,
            'backup.age_recipient' => 'age1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq']);
        $this->createSettings(['trade_name' => 'Bella Napoli', 'backup_disk_uuid' => self::UUID]);
        $this->fakeTools();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory($this->disks);
        parent::tearDown();
    }

    private function connect(): void
    {
        File::ensureDirectoryExists("{$this->disks}/".self::UUID.'/'.BackupDisk::FOLDER);
    }

    private function disconnect(): void
    {
        File::deleteDirectory("{$this->disks}/".self::UUID);
    }

    /** pg_dump | age, age (claves) y tar | age escriben su fichero de salida. */
    private function fakeTools(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
            if (preg_match("/-o '([^']+)'/", $command, $m) || preg_match('/-o (\S+)/', $command, $m)) {
                file_put_contents($m[1], 'cifrado '.uniqid());
            }

            return Process::result('');
        });
    }

    private function onDisk(): array
    {
        $target = BackupDisk::target();

        return $target && is_dir($target) ? collect(File::files($target))->map->getFilename()->sort()->values()->all() : [];
    }

    public function test_each_backup_also_goes_to_the_disk_with_keys_and_files(): void
    {
        $run = app(BackupService::class)->run('cierre_z');

        $this->assertSame('ok', $run->status);
        $this->assertSame('copiada', $run->disk_status);
        $this->assertNotNull($run->disk_at);
        $this->assertSame(['LEEME.txt', $run->file, BackupService::FILES_FILE, BackupService::KEYS_FILE], $this->onDisk());
        $this->assertStringContainsString('bella-napoli-', BackupDisk::target(), 'Una carpeta por local: un disco puede servir a varios.');

        // Las claves (APP_KEY…) van cifradas con age; los archivos, sin impresiones ni exportaciones.
        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'age' && str_contains((string) $p->input, 'APP_KEY='.config('app.key')));
        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'bash' && str_contains($p->command[2], 'tar -C')
            && str_contains($p->command[2], '--exclude=./private/prints') && str_contains($p->command[2], '| age -r '));
    }

    public function test_not_connected_then_copied_as_soon_as_it_is_plugged_in(): void
    {
        $this->disconnect();
        app(BackupService::class)->run('cierre_z');
        app(BackupService::class)->run('diaria');
        $this->assertSame(['no_conectado', 'no_conectado'], BackupRun::query()->orderBy('id')->pluck('disk_status')->all());
        $this->assertStringContainsString('no está conectado', BackupStatus::warning());

        $this->connect();
        $this->artisan('tpv:backup --disk-only')->expectsOutput('Disco aparte al día.')->assertSuccessful();

        $this->assertSame(['copiada', 'copiada'], BackupRun::query()->orderBy('id')->pluck('disk_status')->all());
        // En el disco, lo mismo que hay en el equipo (la rotación deja una copia por día).
        $local = collect(File::files($this->dir))->map->getFilename()->filter(fn ($n) => str_ends_with($n, '.age'))->sort()->values()->all();
        $this->assertSame($local, array_values(array_diff($this->onDisk(), ['LEEME.txt'])));
        $this->assertCount(3, $local, 'Una copia de la base de datos, las claves y los archivos.');
        $this->assertNull(BackupStatus::warning());
    }

    public function test_the_disk_mirrors_the_rotation(): void
    {
        $old = app(BackupService::class)->run('diaria');
        File::delete("{$this->dir}/{$old->file}");   // la rotación la quita del equipo…
        app(BackupService::class)->run('cierre_z');

        $this->assertNotContains($old->file, $this->onDisk(), '…y también del disco.');
    }

    public function test_without_a_chosen_disk_nothing_changes(): void
    {
        Setting::current()->update(['backup_disk_uuid' => null]);

        $run = app(BackupService::class)->run('cierre_z');

        $this->assertSame('sin_disco', $run->disk_status);
        $this->assertNull(app(BackupService::class)->copyToDisk());
    }

    public function test_admin_lists_the_connected_disks_and_counts_the_setup_step(): void
    {
        $this->assertSame('COPIAS · EXFAT · 1 TB · libres '.BackupDisk::bytes((int) disk_free_space($this->disks)), BackupDisk::describe(BackupDisk::available()[0]));

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->role(UserRole::Admin)->create(), 'web');
        Livewire::test(BackupSettings::class)
            ->assertSee('Copia en un disco aparte')
            ->assertSee('COPIAS · EXFAT · 1 TB');

        $this->assertFalse(SetupProgress::steps(Setting::current())['copias']['complete']);
        app(BackupService::class)->run('prueba');
        $this->assertTrue(SetupProgress::steps(Setting::current())['copias']['complete'], 'Con el disco basta (sin destino remoto).');
    }
}
