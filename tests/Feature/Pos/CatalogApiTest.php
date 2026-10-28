<?php

namespace Tests\Feature\Pos;

use App\Events\Pos\CatalogChanged;
use App\Services\Pos\CatalogService;
use Illuminate\Support\Facades\Event;

class CatalogApiTest extends PosTestCase
{
    public function test_catalog_has_version_and_supports_etag(): void
    {
        $this->product('Croquetas', 750);
        $this->pizza('Barbacoa', 1000, 1500);

        $response = $this->asWaiter()->getJson('/api/catalog')->assertOk();
        $version = $response->json('version');
        $this->assertNotEmpty($version);
        $this->assertCount(2, $response->json('data.products'));
        $barbacoa = collect($response->json('data.products'))->firstWhere('name', 'Barbacoa');
        $this->assertSame(['mediana', 'familiar'], collect($barbacoa['variants'])->pluck('code')->all());

        $this->asWaiter()->withHeader('If-None-Match', "\"{$version}\"")->get('/api/catalog')->assertStatus(304);
    }

    public function test_editing_the_catalog_changes_version_and_notifies_once(): void
    {
        Event::fake([CatalogChanged::class]);
        $croquetas = $this->product('Croquetas', 750);
        $before = app(CatalogService::class)->version();

        $croquetas->update(['price' => 800]);
        $croquetas->update(['name' => 'Croquetas caseras']);

        $this->assertNotSame($before, app(CatalogService::class)->version());

        app()->terminate(); // fin de la petición: un solo aviso aunque haya varios cambios
        Event::assertDispatchedTimes(CatalogChanged::class, 1);
    }

    public function test_cached_catalog_is_plain_data(): void
    {
        config(['cache.default' => 'database']);
        $this->product('Croquetas', 750);
        app(CatalogService::class)->get(); // guarda en caché
        $this->app->forgetInstance(CatalogService::class);

        $cached = app(CatalogService::class)->get(); // lee de la caché (deserializa)
        $this->assertSame('Croquetas', $cached['data']['products'][0]['name']);
    }
}
