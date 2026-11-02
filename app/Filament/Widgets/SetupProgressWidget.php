<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SetupWizard;
use App\Models\Setting;
use App\Support\SetupProgress;
use Filament\Widgets\Widget;

/** Tarjeta del panel «Puesta en marcha: X de 7 pasos» hasta terminar. */
class SetupProgressWidget extends Widget
{
    protected string $view = 'filament.widgets.setup-progress';

    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $setting = Setting::query()->first();

        return $setting !== null && SetupWizard::canAccess() && ! SetupProgress::done($setting);
    }

    protected function getViewData(): array
    {
        return [
            'steps' => SetupProgress::steps(),
            'completed' => SetupProgress::completed(),
            'total' => SetupProgress::total(),
            'url' => SetupWizard::getUrl(),
        ];
    }
}
