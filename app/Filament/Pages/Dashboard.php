<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Pages\Dashboard as BaseDashboard;

/** Panel inicial. La primera vez que entra el admin, abre la puesta en marcha. */
class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $setting = Setting::query()->first();
        if ($setting && ! $setting->setup_seen_at && SetupWizard::canAccess()) {
            $this->redirect(SetupWizard::getUrl());
        }
    }
}
