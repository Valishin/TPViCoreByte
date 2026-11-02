<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\TicketSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** tpv:setup: series y ajustes por rellenar; el administrador se crea en /admin. */
class SetupCommandTest extends TestCase
{
    use RefreshDatabase;

    private function runSetup(array $options = []): array
    {
        Artisan::call('tpv:setup', $options + ['--json' => true]);

        return json_decode(trim(Artisan::output()), true);
    }

    public function test_creates_series_and_pending_settings_without_users(): void
    {
        $result = $this->runSetup(['--name' => 'Bar Sant Pol']);

        $this->assertTrue($result['settings_created']);
        $this->assertArrayNotHasKey('setup_code', $result, 'Sin código de configuración.');
        $this->assertSame('Bar Sant Pol', Setting::current()->trade_name);
        $this->assertSame('PENDIENTE', Setting::current()->tax_id);
        $this->assertEqualsCanonicalizing(['T', 'R', 'F', 'FR'], TicketSeries::pluck('code')->all());
        $this->assertSame(0, User::count());
    }

    public function test_is_idempotent(): void
    {
        $this->runSetup();
        Setting::current()->update(['trade_name' => 'Cambiado en /admin']);

        $again = $this->runSetup(['--name' => 'Otro']);
        $this->assertFalse($again['settings_created']);
        $this->assertSame('Cambiado en /admin', Setting::current()->trade_name);
        $this->assertEqualsCanonicalizing(['T', 'R', 'F', 'FR'], TicketSeries::pluck('code')->all());

        User::factory()->role(UserRole::Admin)->create();
        $this->assertFalse($this->runSetup()['settings_created']);
    }
}
