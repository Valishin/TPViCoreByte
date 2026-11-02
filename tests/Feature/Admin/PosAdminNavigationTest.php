<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TPV ↔ /admin: «Administración» lleva al login de /admin (sin entrar solo) e
 * «Ir a la TPV» vuelve a la caja. Las dos sesiones (guard «pos» y «web»)
 * comparten cookie y ninguna cierra la otra.
 */
class PosAdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings();
        // Origin de un dominio «stateful»: Sanctum usa la sesión por cookie, como la SPA.
        $this->withHeader('Origin', 'http://localhost');
        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->role(UserRole::Admin)->create(['email' => 'admin@local.cat', 'password' => 'una-clave-larga']);
        $this->waiter = User::factory()->role(UserRole::Waiter)->withPin('1234')->create();
    }

    private function loginPos(): void
    {
        $this->postJson('/api/login-pin', ['user_id' => $this->waiter->id, 'pin' => '1234'])->assertOk();
    }

    /** Entrar en /admin de verdad (formulario de Filament), en la misma sesión que la caja. */
    private function loginAdmin(): void
    {
        Livewire::test(Login::class)->fillForm(['email' => 'admin@local.cat', 'password' => 'una-clave-larga'])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->app['auth']->forgetGuards();
    }

    public function test_pos_session_does_not_open_admin(): void
    {
        $this->loginPos();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Correo electrónico');
    }

    public function test_admin_logout_keeps_the_pos_session_and_vice_versa(): void
    {
        $this->loginPos();
        $this->loginAdmin();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $this->waiter->id);
        $this->get('/admin')->assertOk();

        $this->post('/admin/logout')->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $this->waiter->id);   // la caja sigue
        $this->get('/admin')->assertRedirect('/admin/login');

        // Y al revés: salir de la caja no cierra /admin.
        $this->loginAdmin();
        $this->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->get('/admin')->assertOk();
    }

    public function test_admin_header_has_go_to_pos_link_same_or_new_tab(): void
    {
        $this->actingAs($this->admin, 'web');
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<a[^>]+href="/"[^>]*>\s*(<[^>]+>\s*)*Ir a la TPV#s', $html);
        $this->assertDoesNotMatchRegularExpression('#href="/"[^>]*target="_blank"#', $html);

        Setting::current()->update(['admin_pos_link_new_tab' => true]);
        $this->assertMatchesRegularExpression('#href="/"[^>]*target="_blank"#', $this->get('/admin')->getContent());
    }
}
