<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Support\SetupCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** Primer acceso a /admin: crear administrador y encargado con el código de un solo uso. */
class FirstRunTest extends TestCase
{
    use RefreshDatabase;

    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings();
        Artisan::call('tpv:setup', ['--json' => true]);
        $this->code = json_decode(trim(Artisan::output()), true)['setup_code'];
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'code' => $this->code, 'name' => 'Alex', 'email' => 'alex@local.cat',
            'password' => 'una-clave-larga', 'password_confirmation' => 'una-clave-larga',
            'manager_name' => 'Marta', 'manager_email' => 'marta@local.cat', 'manager_pin' => '4321', 'manager_pin_confirmation' => '4321',
        ];
    }

    public function test_admin_without_users_goes_to_create_administrator(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $this->code);
        $this->get('/admin/login')->assertRedirect(route('admin.first-run'));
        $this->get('/admin/primer-acceso')->assertOk()->assertSee('Código de configuración');
    }

    public function test_wrong_code_is_rejected_and_creates_nothing(): void
    {
        $this->from('/admin/primer-acceso')->post('/admin/primer-acceso', $this->form(['code' => 'AAAA-BBBB-CCCC']))
            ->assertRedirect('/admin/primer-acceso')->assertSessionHasErrors('code');
        $this->assertSame(0, User::count());
        $this->assertTrue(SetupCode::available());
    }

    public function test_creates_admin_and_manager_then_the_code_and_page_are_gone(): void
    {
        $this->post('/admin/primer-acceso', $this->form(['code' => strtolower(str_replace('-', ' ', $this->code))]))
            ->assertRedirect(route('filament.admin.pages.puesta-en-marcha'));

        $admin = User::where('email', 'alex@local.cat')->sole();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(password_verify('una-clave-larga', $admin->password));
        $this->assertTrue(Auth::guard('web')->check());
        $manager = User::where('role', UserRole::Manager)->sole();
        $this->assertSame('Marta', $manager->name);
        $this->assertTrue($manager->checkPin('4321'));
        $this->assertNull($admin->pin, 'El PIN del admin no se usa en la caja.');

        $this->assertNull(Setting::query()->value('setup_code_hash'));
        $this->get('/admin/primer-acceso')->assertNotFound();
        $this->post('/admin/primer-acceso', $this->form(['email' => 'otro@local.cat']))->assertNotFound();
        $this->assertSame(2, User::count());
    }

    public function test_only_from_the_local_network_or_tailscale(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin/primer-acceso')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->post('/admin/primer-acceso', $this->form())->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '100.101.102.103'])->get('/admin/primer-acceso')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.60'])->get('/admin/primer-acceso')->assertOk();
    }

    public function test_validates_password_and_pin(): void
    {
        $this->post('/admin/primer-acceso', $this->form(['password' => 'corta', 'password_confirmation' => 'corta', 'manager_pin' => '12', 'manager_pin_confirmation' => '12']))
            ->assertSessionHasErrors(['password', 'manager_pin']);
        $this->assertSame(0, User::count());
    }

    public function test_no_code_when_users_already_exist(): void
    {
        User::factory()->role(UserRole::Admin)->create();
        Artisan::call('tpv:setup', ['--json' => true]);
        $this->assertNull(json_decode(trim(Artisan::output()), true)['setup_code']);
        $this->get('/admin/primer-acceso')->assertNotFound();
    }
}
