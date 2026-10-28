<?php

namespace Tests\Feature\Filament;

use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Filament\Pages\LocalSettings;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\RelationManagers\TablesRelationManager;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use App\Services\Orders\OrderService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class AccessTest extends FilamentTestCase
{
    public function test_manager_cannot_access_settings_series_or_users(): void
    {
        $this->actingAsManager();

        $this->get('/admin/ajustes')->assertForbidden();
        $this->get('/admin/usuarios')->assertForbidden();
        $this->get('/admin/series')->assertForbidden();
    }

    public function test_manager_can_access_catalog_customers_and_sales(): void
    {
        $this->actingAsManager();

        foreach (['/admin', '/admin/productos', '/admin/categorias', '/admin/modificadores', '/admin/salas', '/admin/impresoras',
            '/admin/clientes', '/admin/zonas-reparto', '/admin/pedidos', '/admin/tickets', '/admin/cierres-caja'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_can_access_everything(): void
    {
        foreach (['/admin/ajustes', '/admin/usuarios', '/admin/series', '/admin/productos/create'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_waiter_cannot_enter_backoffice(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Waiter]));

        $this->get('/admin')->assertForbidden();
    }

    public function test_admin_saves_settings(): void
    {
        Livewire::test(LocalSettings::class)
            ->fillForm(['trade_name' => 'Bar Nuevo', 'delivery_fee_vat_rate' => 2100])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Bar Nuevo', Setting::current()->trade_name);
        $this->assertSame(2100, Setting::current()->delivery_fee_vat_rate);
    }

    public function test_email_is_required_for_manager_in_form_and_model(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Eva', 'role' => UserRole::Manager->value, 'email' => null, 'new_pin' => '1234', 'password' => 'secreta123'])
            ->call('create')
            ->assertHasFormErrors(['email' => 'required']);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Pepe', 'role' => UserRole::Waiter->value, 'email' => null, 'new_pin' => '1234'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(User::where('name', 'Pepe')->first()->checkPin('1234'));

        $this->expectException(ValidationException::class);
        User::factory()->create(['role' => UserRole::Admin, 'email' => null]);
    }

    public function test_table_with_open_order_cannot_be_deactivated(): void
    {
        $table = Table::factory()->create();
        app(OrderService::class)->open(OrderType::Table, $this->admin, $table);

        Livewire::test(TablesRelationManager::class, [
            'ownerRecord' => $table->room,
            'pageClass' => EditRoom::class,
        ])
            ->callAction(TestAction::make('edit')->table($table), data: ['is_active' => false])
            ->assertHasActionErrors(['is_active']);

        $this->assertTrue($table->fresh()->is_active);

        $this->expectException(ValidationException::class);
        $table->update(['is_active' => false]);
    }
}
