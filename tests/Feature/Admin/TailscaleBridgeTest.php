<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\SetupWizard;
use App\Models\Setting;
use App\Support\SetupProgress;
use App\Support\TailscaleBridge;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Feature\Filament\FilamentTestCase;

/** Acceso remoto: la app deja la clave en la carpeta compartida; el servicio del equipo deja el estado. */
class TailscaleBridgeTest extends FilamentTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tpv-ts-'.uniqid();
        mkdir($this->dir, 0700);
        config(['tpv.tailscale_bridge' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_submits_the_key_only_readable_by_the_app_user(): void
    {
        TailscaleBridge::submit('tskey-auth-kAbc123CNTRL-xyz987', 'tpv-Bella Napoli');

        $file = "{$this->dir}/authkey";
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertSame(['authkey' => 'tskey-auth-kAbc123CNTRL-xyz987', 'hostname' => 'tpv-bella-napoli', 'ssh' => false], json_decode(file_get_contents($file), true));
        $this->assertSame('pendiente', TailscaleBridge::status()['state']);
        $this->assertSame([], glob("{$this->dir}/.authkey-*"));
    }

    public function test_rejects_something_that_is_not_an_auth_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TailscaleBridge::submit('tskey-api-xxxxxxxxxxxx', 'tpv');
    }

    public function test_reads_the_status_left_by_the_host_service(): void
    {
        file_put_contents("{$this->dir}/status.json", json_encode(['state' => 'conectado', 'message' => '', 'hostname' => 'tpv-bella-napoli', 'ip' => '100.64.1.2', 'at' => '2026-11-02T10:00:00+01:00']));
        $this->assertTrue(TailscaleBridge::connected());
        $this->assertStringContainsString('Conectado como tpv-bella-napoli (100.64.1.2)', TailscaleBridge::describe());
        $this->assertTrue(SetupProgress::steps()['remoto']['complete']);

        file_put_contents("{$this->dir}/status.json", json_encode(['state' => 'conectando', 'hostname' => 'tpv-bella-napoli']));
        $this->assertStringStartsWith('Conectando', TailscaleBridge::describe());
        $this->assertFalse(TailscaleBridge::connected());

        file_put_contents("{$this->dir}/status.json", json_encode(['state' => 'error', 'message' => 'invalid key']));
        $this->assertFalse(TailscaleBridge::connected());
        $this->assertStringContainsString('invalid key', TailscaleBridge::describe());
    }

    public function test_without_the_installer_service_it_says_so(): void
    {
        config(['tpv.tailscale_bridge' => '/no/existe']);
        $this->assertFalse(TailscaleBridge::available());
        $this->assertStringContainsString('no tiene el servicio', TailscaleBridge::describe());
    }

    public function test_wizard_step_sends_the_key_and_never_stores_it(): void
    {
        Livewire::test(SetupWizard::class)
            ->fillForm(['tailscale_authkey' => 'no-es-una-clave'])->goToWizardStep(7)
            ->assertHasFormErrors(['tailscale_authkey'])
            ->fillForm(['tailscale_authkey' => 'tskey-auth-kAbc123CNTRL-xyz987'])->goToWizardStep(7)
            ->assertHasNoFormErrors()
            ->assertNotified('Clave enviada: el equipo se une a Tailscale en unos segundos');

        $this->assertFileExists("{$this->dir}/authkey");
        $this->assertStringNotContainsString('tskey-auth', json_encode(Setting::current()->getAttributes()));
    }

    public function test_ssh_is_off_until_allowed_by_hand_with_the_warning(): void
    {
        $this->assertFalse(Setting::current()->tailscale_ssh);
        file_put_contents("{$this->dir}/status.json", json_encode(['state' => 'conectado', 'hostname' => 'tpv-bella', 'ssh' => 'false']));
        $this->assertStringContainsString('SSH por Tailscale desactivado', TailscaleBridge::describe());

        $wizard = Livewire::test(SetupWizard::class);
        $wizard->mountAction(TestAction::make('allowTailscaleSsh')->schemaComponent('puesta-en-marcha.remoto.tailscale-ssh', 'form'))
            ->assertMountedActionModalSee('podrán abrir una consola en este mini PC sin su contraseña')
            ->callMountedAction()->assertHasActionErrors(['understood']);
        $this->assertFalse(Setting::current()->tailscale_ssh, 'Sin marcar «Entiendo lo que implica» no se activa.');

        Livewire::test(SetupWizard::class)
            ->callAction(TestAction::make('allowTailscaleSsh')->schemaComponent('puesta-en-marcha.remoto.tailscale-ssh', 'form'), ['understood' => true])
            ->assertNotified('Acceso SSH por Tailscale permitido');
        $this->assertTrue(Setting::current()->tailscale_ssh);
        $this->assertSame(['ssh' => true], json_decode(file_get_contents("{$this->dir}/ssh-request"), true));

        Livewire::test(SetupWizard::class)
            ->callAction(TestAction::make('revokeTailscaleSsh')->schemaComponent('puesta-en-marcha.remoto.tailscale-ssh', 'form'));
        $this->assertFalse(Setting::current()->tailscale_ssh);
        $this->assertSame(['ssh' => false], json_decode(file_get_contents("{$this->dir}/ssh-request"), true));
    }

    public function test_joining_sends_the_chosen_ssh_setting(): void
    {
        Setting::current()->update(['tailscale_ssh' => true]);
        Livewire::test(SetupWizard::class)->fillForm(['tailscale_authkey' => 'tskey-auth-kAbc123CNTRL-xyz987'])->goToWizardStep(7);
        $this->assertTrue(json_decode(file_get_contents("{$this->dir}/authkey"), true)['ssh']);
    }
}
