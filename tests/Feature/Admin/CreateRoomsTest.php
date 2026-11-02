<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Models\Room;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Nueva sala en /admin: Salón, Barra y Terraza para marcar y una personalizada. */
class CreateRoomsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings();
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->role(UserRole::Admin)->create(), 'web');
    }

    public function test_creates_the_checked_presets_and_the_custom_one_at_once(): void
    {
        Livewire::test(CreateRoom::class)
            ->assertSee(['Salón', 'Barra', 'Terraza', 'Otra sala (personalizada)'])
            ->fillForm(['presets' => ['Terraza', 'Salón'], 'custom' => ' Reservado '])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(route('filament.admin.resources.salas.index'));

        $this->assertSame(['Salón', 'Terraza', 'Reservado'], Room::query()->orderBy('sort_order')->pluck('name')->all());
    }

    public function test_only_a_custom_room(): void
    {
        Livewire::test(CreateRoom::class)
            ->fillForm(['presets' => [], 'custom' => 'Comedor'])
            ->call('create')
            ->assertHasNoFormErrors();

        $room = Room::query()->sole();
        $this->assertSame('Comedor', $room->name);
    }

    public function test_needs_something_and_never_duplicates(): void
    {
        Room::factory()->create(['name' => 'Salón']);

        Livewire::test(CreateRoom::class)
            ->fillForm(['presets' => [], 'custom' => ''])
            ->call('create')
            ->assertHasFormErrors(['presets']);

        Livewire::test(CreateRoom::class)
            ->fillForm(['presets' => [], 'custom' => 'salón'])
            ->call('create')
            ->assertHasErrors(['data.custom']);

        $this->assertSame(1, Room::query()->count());
    }

    public function test_edit_keeps_the_name_field(): void
    {
        $room = Room::factory()->create(['name' => 'Terraza']);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->fillForm(['name' => 'Terraza cubierta'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Terraza cubierta', $room->fresh()->name);
    }
}
