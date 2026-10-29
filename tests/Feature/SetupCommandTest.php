<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TicketSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SetupCommandTest extends TestCase
{
    use RefreshDatabase;

    private function runSetup(array $options): array
    {
        Artisan::call('tpv:setup', $options + ['--json' => true]);

        return json_decode(trim(Artisan::output()), true);
    }

    public function test_creates_local_series_and_admin_without_demo_data(): void
    {
        $result = $this->runSetup(['--name' => 'Bar Sant Pol', '--admin-email' => 'jefa@barsantpol.cat', '--types' => 'mesa,barra,para_llevar']);

        $this->assertTrue($result['settings_created']);
        $this->assertSame(16, strlen($result['admin_password']));
        $this->assertMatchesRegularExpression('/^\d{4}$/', $result['admin_pin']);
        $this->assertSame('Bar Sant Pol', Setting::current()->trade_name);
        $this->assertSame(['mesa', 'barra', 'para_llevar'], Setting::current()->enabled_order_types->map->value->all());
        $this->assertEqualsCanonicalizing(['T', 'R'], TicketSeries::pluck('code')->all());
        $admin = User::where('email', 'jefa@barsantpol.cat')->sole();
        $this->assertTrue(password_verify($result['admin_password'], $admin->password));
        $this->assertTrue($admin->checkPin($result['admin_pin']));
        $this->assertSame(1, User::count()); // nada de demo
    }

    public function test_is_idempotent(): void
    {
        $first = $this->runSetup(['--name' => 'Bar', '--admin-email' => 'a@b.cat']);
        Setting::current()->update(['trade_name' => 'Cambiado en /admin']);

        $again = $this->runSetup(['--name' => 'Otro', '--admin-email' => 'a@b.cat']);

        $this->assertFalse($again['settings_created']);
        $this->assertNull($again['admin_password']);
        $this->assertSame('Cambiado en /admin', Setting::current()->trade_name);
        $this->assertTrue(password_verify($first['admin_password'], User::sole()->password));
        $this->assertSame(2, TicketSeries::count());

        $reset = $this->runSetup(['--admin-email' => 'a@b.cat', '--reset-password' => true]);
        $this->assertNotSame($first['admin_password'], $reset['admin_password']);
    }

    public function test_requires_a_valid_email(): void
    {
        $this->assertSame(1, Artisan::call('tpv:setup', ['--admin-email' => 'no-es-email']));
    }
}
