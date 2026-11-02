<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\DeliveryZones\Pages\CreateDeliveryZone;
use App\Filament\Resources\DeliveryZones\Pages\EditDeliveryZone;
use App\Models\DeliveryZone;
use App\Models\User;
use App\Support\PostalCode;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Zona de reparto en /admin: cada código postal con su municipio (y la provincia sale sola). */
class DeliveryZoneCitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings();
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->role(UserRole::Admin)->create(), 'web');
    }

    public function test_each_postal_code_is_saved_with_its_town(): void
    {
        Livewire::test(CreateDeliveryZone::class)
            ->fillForm([
                'name' => 'Costa', 'delivery_fee' => 150, 'minimum_order' => 1500, 'is_active' => true,
                'areas' => [
                    ['postal_code' => '17220', 'city' => 'Sant Feliu de Guíxols'],
                    ['postal_code' => '17250', 'city' => "Platja d'Aro"],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $zone = DeliveryZone::query()->sole();
        $this->assertSame(['17220', '17250'], $zone->postal_codes, 'La búsqueda de zona por CP sigue igual.');
        $this->assertSame("Platja d'Aro", $zone->cityFor('17250'));
        $this->assertSame('Sant Feliu de Guíxols', PostalCode::city('17220'));
        $this->assertSame('17220 Sant Feliu de Guíxols (Girona)', PostalCode::place('17220', null));
    }

    public function test_postal_code_needs_five_digits_and_a_town(): void
    {
        Livewire::test(CreateDeliveryZone::class)
            ->fillForm(['name' => 'Mal', 'delivery_fee' => 0, 'minimum_order' => 0, 'areas' => [['postal_code' => '172', 'city' => '']]])
            ->call('create')
            ->assertHasFormErrors();
        $this->assertSame(0, DeliveryZone::query()->count());
    }

    public function test_edit_shows_and_keeps_the_towns(): void
    {
        $zone = DeliveryZone::create(['name' => 'Sant Feliu', 'delivery_fee' => 0, 'minimum_order' => 0,
            'postal_codes' => ['17220'], 'postal_code_cities' => ['17220' => 'Sant Feliu de Guíxols']]);

        Livewire::test(EditDeliveryZone::class, ['record' => $zone->getRouteKey()])
            ->assertSee('Sant Feliu de Guíxols')
            ->fillForm(['areas' => [['postal_code' => '17220', 'city' => 'Sant Feliu de Guíxols'], ['postal_code' => '17248', 'city' => "S'Agaró"]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $zone->refresh();
        $this->assertSame(['17220', '17248'], $zone->postal_codes);
        $this->assertSame("S'Agaró", $zone->cityFor('17248'));
    }

    public function test_the_zone_town_wins_for_addresses_and_the_delivery_ticket(): void
    {
        DeliveryZone::create(['name' => 'Costa', 'delivery_fee' => 0, 'minimum_order' => 0,
            'postal_codes' => ['17248'], 'postal_code_cities' => ['17248' => "S'Agaró"]]);

        $this->assertSame("S'Agaró", PostalCode::city('17248'));
        $this->assertSame("17248 S'Agaró (Girona)", PostalCode::place('17248', ''));
    }
}
