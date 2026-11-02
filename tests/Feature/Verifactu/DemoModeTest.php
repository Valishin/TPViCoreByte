<?php

namespace Tests\Feature\Verifactu;

use App\Enums\UserRole;
use App\Filament\Pages\Verifactu as VerifactuPage;
use App\Models\FiscalRecord;
use App\Models\Setting;
use App\Models\User;
use App\Services\Verifactu\Verifactu;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use LogicException;
use Tests\Feature\Pos\InvoiceTestCase;

/** Instalación de demostración: VERI*FACTU no se activa por ningún camino. */
class DemoModeTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['tpv.install_mode' => 'demo']);
        Queue::fake();
    }

    public function test_cannot_be_enabled_from_code(): void
    {
        try {
            Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()]);
            $this->fail('En demo no se puede activar VERI*FACTU.');
        } catch (LogicException $e) {
            $this->assertSame('Instalación de demostración: VERI*FACTU no se puede activar.', $e->getMessage());
        }
        $this->assertFalse((bool) Setting::current()->fresh()->verifactu_enabled);
    }

    public function test_cannot_be_enabled_from_admin_even_calling_the_hidden_action(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create(), 'web');
        Filament::setCurrentPanel('admin');

        // Oculta, y Filament no deja montarla aunque se llame a mano desde el navegador.
        Livewire::test(VerifactuPage::class)->assertActionHidden('activate')
            ->call('mountAction', 'activate')->assertActionNotMounted('activate');

        $this->assertFalse((bool) Setting::current()->verifactu_enabled);
    }

    public function test_no_api_route_can_enable_it(): void
    {
        // Ninguna ruta de la API de la caja toca VERI*FACTU; un PATCH genérico tampoco existe.
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $this->assertDoesNotMatchRegularExpression('/verifactu\/(activ|enable)|settings/i', $route->uri());
            }
        }
        $this->asWaiter()->patchJson('/api/settings', ['verifactu_enabled' => true])->assertStatus(404);
        $this->assertFalse((bool) Setting::current()->verifactu_enabled);
    }

    public function test_a_database_already_enabled_does_not_work_in_demo(): void
    {
        // P. ej. una copia de un cliente restaurada en un equipo de demo.
        Setting::query()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subDay()]);
        $settings = Setting::current()->fresh();

        $this->assertFalse(Verifactu::active($settings));
        $this->openCash();
        $this->checkout($this->orderWith([[$this->pizza, 1]]), [['method' => 'efectivo', 'amount' => 1000]])->assertCreated();
        $this->assertSame(0, FiscalRecord::query()->count(), 'Sin registros de facturación ni envíos.');
    }
}
