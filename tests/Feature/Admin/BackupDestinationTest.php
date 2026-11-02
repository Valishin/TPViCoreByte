<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\BackupSettings;
use App\Jobs\RunBackup;
use App\Models\BackupRun;
use App\Models\Setting;
use App\Services\Backup\BackupDestination;
use App\Services\Backup\BackupService;
use App\Support\SetupProgress;
use Filament\Actions\Testing\TestAction;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Filament\FilamentTestCase;

/** Copias desde /admin: clave age, destino S3 o SFTP (clave generada aquí) y «Probar copia». */
class BackupDestinationTest extends FilamentTestCase
{
    private const AGE = 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p';

    private const KNOWN = 'copias.example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

    public function test_s3_destination_is_passed_to_rclone_by_environment(): void
    {
        Livewire::test(BackupSettings::class)
            ->fillForm(['backup_age_recipient' => self::AGE, 'backup_remote_type' => 's3', 'backup_s3_endpoint' => 'https://s3.eu.example.com',
                'backup_s3_bucket' => 'copias', 'backup_s3_access_key' => 'AKIA1', 'backup_s3_secret_key' => 'secreto-s3', 'backup_path' => '/bella-napoli/'])
            ->call('save')->assertHasNoFormErrors();

        $s = Setting::current();
        $this->assertSame('tpvcopias:copias/bella-napoli', BackupDestination::remote($s));
        $this->assertSame(['RCLONE_CONFIG_TPVCOPIAS_TYPE' => 's3', 'RCLONE_CONFIG_TPVCOPIAS_PROVIDER' => 'Other',
            'RCLONE_CONFIG_TPVCOPIAS_ENDPOINT' => 'https://s3.eu.example.com', 'RCLONE_CONFIG_TPVCOPIAS_ACCESS_KEY_ID' => 'AKIA1',
            'RCLONE_CONFIG_TPVCOPIAS_SECRET_ACCESS_KEY' => 'secreto-s3', 'RCLONE_CONFIG_TPVCOPIAS_NO_CHECK_BUCKET' => 'true'], BackupDestination::env($s));
        $this->assertStringNotContainsString('secreto-s3', (string) DB::table('settings')->value('backup_s3_secret_key'));

        // Guardar sin volver a escribir el secreto lo conserva.
        Livewire::test(BackupSettings::class)->fillForm(['backup_s3_bucket' => 'copias2'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('secreto-s3', Setting::current()->backup_s3_secret_key);
    }

    public function test_sftp_key_is_generated_here_and_shown_to_copy(): void
    {
        Setting::current()->update(['backup_age_recipient' => self::AGE, 'backup_remote_type' => 'sftp', 'backup_sftp_host' => 'copias.example.com', 'backup_sftp_user' => 'tpv']);

        $public = BackupDestination::generateSftpKey();

        $this->assertMatchesRegularExpression('/^ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI[A-Za-z0-9+\/]+=* tpv-copias@/', $public);
        $s = Setting::current();
        $this->assertSame($public, $s->backup_sftp_public_key);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $s->backup_sftp_private_key);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', (string) DB::table('settings')->value('backup_sftp_private_key'));
        $env = BackupDestination::env($s);
        $this->assertSame(['sftp', 'copias.example.com', '22', 'tpv'], [$env['RCLONE_CONFIG_TPVCOPIAS_TYPE'], $env['RCLONE_CONFIG_TPVCOPIAS_HOST'],
            $env['RCLONE_CONFIG_TPVCOPIAS_PORT'], $env['RCLONE_CONFIG_TPVCOPIAS_USER']]);
        $this->assertStringNotContainsString("\n", $env['RCLONE_CONFIG_TPVCOPIAS_KEY_PEM']);
        $this->assertSame('tpvcopias:tpv-copias', BackupDestination::remote($s));
    }

    public function test_upload_uses_the_admin_destination(): void
    {
        Process::fake();
        Setting::current()->update(['backup_age_recipient' => self::AGE, 'backup_remote_type' => 'sftp', 'backup_sftp_host' => 'h', 'backup_sftp_user' => 'u',
            'backup_sftp_known_hosts' => self::KNOWN]);
        BackupRun::create(['reason' => 'prueba', 'file' => 'tpv-20261102-100000-prueba.dump.age', 'status' => 'ok', 'remote_status' => 'pendiente']);

        $this->assertTrue(app(BackupService::class)->upload());

        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'rclone' && $p->command[3] === 'tpvcopias:tpv-copias'
            && ($p->environment['RCLONE_CONFIG_TPVCOPIAS_HOST'] ?? null) === 'h'
            && file_get_contents($p->environment['RCLONE_CONFIG_TPVCOPIAS_KNOWN_HOSTS_FILE']) === self::KNOWN."\n");
        $this->assertSame('subida', BackupRun::query()->sole()->remote_status);
        $this->assertTrue(SetupProgress::steps()['copias']['complete']);
    }

    public function test_probar_copia_saves_and_runs_a_backup(): void
    {
        Queue::fake();
        Setting::current()->update(['backup_sftp_known_hosts' => self::KNOWN]);
        Livewire::test(BackupSettings::class)
            ->fillForm(['backup_age_recipient' => self::AGE, 'backup_remote_type' => 'sftp', 'backup_sftp_host' => 'h', 'backup_sftp_user' => 'u'])
            ->callAction(TestAction::make('testBackup')->schemaComponent('backup-test', 'form'))
            ->assertNotified('Copia de prueba en marcha');

        Queue::assertPushed(RunBackup::class, fn ($job) => $job->reason === 'prueba');
        $this->assertSame('h', Setting::current()->backup_sftp_host);
        $this->assertSame('Todavía no se ha hecho ninguna copia.', BackupSettings::lastRunText());
    }

    public function test_falls_back_to_the_env_destination(): void
    {
        config(['backup.age_recipient' => self::AGE, 'backup.remote' => 'copias:/srv/copias']);
        $this->assertSame('copias:/srv/copias', BackupDestination::remote());
        $this->assertSame([], BackupDestination::env());
        $this->assertTrue(BackupDestination::configured());
    }

    public function test_rejects_a_key_that_is_not_age(): void
    {
        Livewire::test(BackupSettings::class)->fillForm(['backup_age_recipient' => 'ssh-rsa AAAA', 'backup_remote_type' => 'sftp', 'backup_sftp_host' => 'h', 'backup_sftp_user' => 'u'])
            ->call('save')->assertHasFormErrors(['backup_age_recipient']);
    }

    public function test_sftp_needs_the_confirmed_server_key(): void
    {
        Process::fake([
            '*ssh-keyscan*' => Process::result(self::KNOWN."\n", '# copias.example.com:22 SSH-2.0-OpenSSH_9.6'),
            '*ssh-keygen*' => Process::result('256 SHA256:Xm5c0bxrSmDzyKdoTfV1J3OJ6ObKz8NnGPyRCv1Q2ok copias.example.com (ED25519)'),
        ]);
        Setting::current()->update(['backup_age_recipient' => self::AGE, 'backup_remote_type' => 'sftp', 'backup_sftp_host' => 'copias.example.com', 'backup_sftp_user' => 'u']);
        $this->assertFalse(BackupDestination::configured(), 'Sin la clave del servidor no se usa SFTP.');

        $scan = BackupDestination::scanSftpHost('copias.example.com', 2222);
        $this->assertSame(self::KNOWN, $scan['known_hosts']);
        $this->assertSame(['256 SHA256:Xm5c0bxrSmDzyKdoTfV1J3OJ6ObKz8NnGPyRCv1Q2ok copias.example.com (ED25519)'], $scan['fingerprints']);
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['ssh-keyscan', '-T', '10', '-p', '2222', '-t', 'ed25519,ecdsa,rsa', 'copias.example.com']);

        Livewire::test(BackupSettings::class)
            ->callAction(TestAction::make('scanHostKey')->schemaComponent('sftp-actions', 'form'));
        $this->assertSame(self::KNOWN, Setting::current()->backup_sftp_known_hosts);
        $this->assertTrue(BackupDestination::configured());
    }

    public function test_unreachable_sftp_server_says_so(): void
    {
        Process::fake(['*ssh-keyscan*' => Process::result('', 'getaddrinfo copias.example.com: Name or service not known', 1)]);
        $this->expectExceptionMessage('No se ha podido leer la clave de copias.example.com:22');
        BackupDestination::scanSftpHost('copias.example.com');
    }
}
