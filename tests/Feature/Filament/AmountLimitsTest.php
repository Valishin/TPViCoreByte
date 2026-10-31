<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Filament\Pages\LocalSettings;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

/** Importes máximos (fondo, arqueo, entregado, propina): se editan en /admin y llegan al TPV. */
class AmountLimitsTest extends FilamentTestCase
{
    public function test_defaults_reach_the_pos_and_admin_can_change_them(): void
    {
        $waiter = User::factory()->role(UserRole::Waiter)->withPin('1234')->create();
        $this->actingAs($waiter, 'pos')->getJson('/api/me')->assertOk()->assertJsonPath('settings.amount_limits', [
            'opening_float' => 200000, 'cash_count' => 1000000, 'cash_tendered' => 100000, 'tip' => 5000,
        ]);

        $this->actingAs($this->admin, 'web');
        Livewire::test(LocalSettings::class)
            ->assertFormSet(['max_opening_float' => '2000,00'])
            ->fillForm(['max_opening_float' => '1500,00', 'max_cash_tendered' => '500'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame([150000, 50000], [Setting::current()->max_opening_float, Setting::current()->max_cash_tendered]);
    }
}
