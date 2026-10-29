<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LocalSettings;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Models\Category;
use App\Models\Setting;
use Livewire\Livewire;

class QuickNotesAdminTest extends FilamentTestCase
{
    /** Estado del repetidor simple en el formulario: un elemento por nota. */
    private static function items(string ...$notes): array
    {
        return array_map(fn (string $note) => ['note' => $note], $notes);
    }

    public function test_category_quick_notes_are_saved_in_order(): void
    {
        $category = Category::factory()->create(['name' => 'Refrescos']);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->fillForm(['quick_notes' => self::items('Sin hielo', 'Con limón', 'Del tiempo')])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Sin hielo', 'Con limón', 'Del tiempo'], $category->fresh()->quick_notes);
    }

    public function test_quick_notes_must_be_short(): void
    {
        $category = Category::factory()->create();

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->fillForm(['quick_notes' => self::items(str_repeat('x', 41))])
            ->call('save')
            ->assertHasFormErrors();
    }

    public function test_settings_save_general_notes_and_keyboard_toggle(): void
    {
        $this->assertTrue(Setting::current()->show_onscreen_keyboard_pos);

        Livewire::test(LocalSettings::class)
            ->fillForm(['quick_notes' => self::items('Para compartir', 'Rápido'), 'show_onscreen_keyboard_pos' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = Setting::current();
        $this->assertSame(['Para compartir', 'Rápido'], $settings->quick_notes);
        $this->assertFalse($settings->show_onscreen_keyboard_pos);
    }
}
