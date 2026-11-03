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

    public function test_admin_saves_takeaway_and_delivery_settings(): void
    {
        Livewire::test(LocalSettings::class)
            ->fillForm(['takeaway_prep_minutes' => 20, 'delivery_minutes' => 45, 'print_courier_copy' => true, 'print_courier_settlement' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = Setting::current();
        $this->assertSame(20, $settings->takeaway_prep_minutes);
        $this->assertSame(45, $settings->delivery_minutes);
        $this->assertTrue($settings->print_courier_copy);
        $this->assertFalse($settings->print_courier_settlement);
    }

    /** Avisos de tiempo: /admin → Ajustes del local → «Avisos de tiempo», y la TPV los recibe. */
    public function test_admin_turns_time_alerts_on_and_off_and_the_pos_gets_them(): void
    {
        Livewire::test(LocalSettings::class)
            ->assertSee('Avisos de tiempo')
            ->fillForm(['time_alerts_enabled' => true, 'time_alert_takeaway_minutes' => 15, 'time_alert_table_minutes' => 90])
            ->call('save')
            ->assertHasNoFormErrors();
        $settings = Setting::current();
        $this->assertTrue($settings->time_alerts_enabled);
        $this->assertSame(15, $settings->time_alert_takeaway_minutes);
        $this->assertSame(90, $settings->time_alert_table_minutes);

        Livewire::test(LocalSettings::class)->fillForm(['time_alerts_enabled' => false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse(Setting::current()->time_alerts_enabled);

        $waiter = User::factory()->role(UserRole::Waiter)->withPin('1234')->create();
        $this->app['auth']->forgetGuards();
        $this->actingAs($waiter, 'pos')->getJson('/api/me')->assertOk()
            ->assertJsonPath('settings.time_alerts', ['enabled' => false, 'takeaway_minutes' => 15, 'table_minutes' => 90]);
    }
}
