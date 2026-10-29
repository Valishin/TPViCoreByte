<?php

namespace Tests\Feature\Pos;

use App\Models\Setting;
use App\Services\Pos\CatalogService;

/** Notas rápidas por categoría y generales en la carta del TPV, y el ajuste del teclado en pantalla. */
class QuickNotesTest extends PosTestCase
{
    public function test_catalog_includes_category_and_general_quick_notes_in_order(): void
    {
        $this->drinks->update(['quick_notes' => ['Sin hielo', 'Con limón']]);
        $this->food->update(['quick_notes' => ['Poco hecha', 'Al punto']]);
        Setting::current()->update(['quick_notes' => ['Para compartir', 'Sin gluten']]);

        $data = $this->asWaiter()->getJson('/api/catalog')->assertOk()->json('data');

        $categories = collect($data['categories'])->keyBy('name');
        $this->assertSame(['Sin hielo', 'Con limón'], $categories['Bebida']['quick_notes']);
        $this->assertSame(['Poco hecha', 'Al punto'], $categories['Comida']['quick_notes']);
        $this->assertSame(['Para compartir', 'Sin gluten'], $data['quick_notes']);
    }

    public function test_quick_notes_are_trimmed_deduplicated_and_short(): void
    {
        $this->drinks->update(['quick_notes' => ['  Sin   hielo ', 'sin hielo', '', null, str_repeat('a', 60), 'Con limón']]);

        $this->assertSame(['Sin hielo', str_repeat('a', 40), 'Con limón'], $this->drinks->fresh()->quick_notes);
    }

    public function test_changing_general_quick_notes_refreshes_the_catalog_version(): void
    {
        $before = app(CatalogService::class)->version();

        Setting::current()->update(['quick_notes' => ['Rápido']]);
        $this->assertNotSame($before, $after = app(CatalogService::class)->version());

        // Otros ajustes no tocan la carta.
        Setting::current()->update(['trade_name' => 'Otro nombre']);
        $this->assertSame($after, app(CatalogService::class)->version());
    }

    public function test_new_category_has_no_quick_notes(): void
    {
        $category = $this->drinks->replicate()->fill(['name' => 'Nueva', 'quick_notes' => null]);
        $category->save();

        $this->assertSame([], $category->fresh()->quick_notes);
    }

    public function test_onscreen_keyboard_setting_is_on_by_default_and_reaches_the_pos(): void
    {
        $this->asWaiter()->getJson('/api/me')->assertOk()->assertJsonPath('settings.show_onscreen_keyboard_pos', true);

        Setting::current()->update(['show_onscreen_keyboard_pos' => false]);

        $this->asWaiter()->getJson('/api/me')->assertJsonPath('settings.show_onscreen_keyboard_pos', false);
    }

    public function test_login_returns_the_onscreen_keyboard_setting(): void
    {
        $this->withHeader('Origin', 'http://localhost')->postJson('/api/login-pin', ['user_id' => $this->waiter->id, 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('settings.show_onscreen_keyboard_pos', true);
    }
}
