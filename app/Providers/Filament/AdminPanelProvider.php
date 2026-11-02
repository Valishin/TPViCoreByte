<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Http\Middleware\RedirectToFirstRun;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('TPV · Backoffice')
            // En la pantalla de entrada, el logo de iCOREBYTE; dentro, el nombre en texto.
            ->brandLogo(fn () => request()->routeIs('filament.admin.auth.*') ? asset('images/icorebyte-logo.png') : null)
            ->brandLogoHeight('2.75rem')
            // Aviso si las copias de seguridad no están al día.
            ->renderHook(PanelsRenderHook::PAGE_START, fn () => view('filament.backup-warning'))
            ->renderHook(PanelsRenderHook::PAGE_START, fn () => view('filament.verifactu-notices'))
            // «Ir a la TPV» en la cabecera.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.pos-link'))
            // Soporte técnico debajo del formulario de entrada.
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('filament.support-contact'))
            // Versión instalada, al pie de todas las páginas.
            ->renderHook(PanelsRenderHook::FOOTER, fn (): string => '<div style="padding:1rem;text-align:center;font-size:.75rem;opacity:.6">TPV '
                .e(config('app.version')).(config('app.commit') ? ' · '.e(config('app.commit')) : '').'</div>')
            ->colors([
                'primary' => Color::Orange,
            ])
            ->navigationGroups([
                'Carta',
                'Sala',
                'Clientes y reparto',
                'Ventas',
                'Configuración',
            ])
            ->sidebarCollapsibleOnDesktop()
            ->unsavedChangesAlerts()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                RedirectToFirstRun::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
