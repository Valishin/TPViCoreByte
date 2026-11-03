<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** /admin: se entra con el email o con el nombre de usuario (opcional, sin distinguir mayúsculas). */
class AdminUsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings();
        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->role(UserRole::Admin)->create([
            'email' => 'alex@local.cat', 'username' => ' Alex ', 'password' => 'una-clave-larga',
        ]);
    }

    private function login(string $login, string $password = 'una-clave-larga')
    {
        return Livewire::test(Login::class)->fillForm(['email' => $login, 'password' => $password])->call('authenticate');
    }

    public function test_username_is_stored_trimmed_and_lowercase(): void
    {
        $this->assertSame('alex', $this->admin->username);
        $this->assertNull(User::factory()->create(['username' => '  '])->username);
    }

    public function test_login_with_username_or_email(): void
    {
        $this->login('alex')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->admin, 'web');
        auth('web')->logout();

        $this->login('ALEX')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->admin, 'web');
        auth('web')->logout();

        $this->login('alex@local.cat')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->admin, 'web');
    }

    public function test_wrong_password_or_unknown_user_fails(): void
    {
        $this->login('alex', 'otra-clave-mala')->assertHasFormErrors(['email']);
        $this->login('nadie')->assertHasFormErrors(['email']);
        $this->assertGuest('web');
    }

    public function test_waiter_with_username_cannot_enter_admin(): void
    {
        User::factory()->role(UserRole::Waiter)->create(['username' => 'pepe', 'password' => 'una-clave-larga']);

        $this->login('pepe');
        $this->assertGuest('web');
    }

    public function test_username_is_set_in_the_users_form_and_unique_ignoring_case(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Eva', 'role' => UserRole::Manager->value, 'email' => 'eva@local.cat', 'username' => 'Eva.Sala',
                'new_pin' => '1234', 'password' => 'secreta123'])
            ->call('create')
            ->assertHasNoFormErrors();
        $eva = User::where('email', 'eva@local.cat')->sole();
        $this->assertSame('eva.sala', $eva->username);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Otra', 'role' => UserRole::Manager->value, 'email' => 'otra@local.cat', 'username' => 'EVA.sala',
                'new_pin' => '1234', 'password' => 'secreta123'])
            ->call('create')
            ->assertHasFormErrors(['username']);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Otra', 'role' => UserRole::Manager->value, 'email' => 'otra@local.cat', 'username' => 'con espacio',
                'new_pin' => '1234', 'password' => 'secreta123'])
            ->call('create')
            ->assertHasFormErrors(['username' => 'regex']);

        // Editar sin cambiarlo no choca consigo mismo; vaciarlo lo quita.
        Livewire::test(EditUser::class, ['record' => $eva->getRouteKey()])
            ->fillForm(['username' => 'eva.sala'])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditUser::class, ['record' => $eva->getRouteKey()])
            ->fillForm(['username' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertNull($eva->fresh()->username);
    }
}
