<?php

namespace App\Filament\Pages;

use App\Support\SystemDeclaration;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** «Acerca de»: datos del sistema y declaración responsable (RRSIF 13.2; OM 15.3). Visible para todo el backoffice. */
class About extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationLabel = 'Acerca de';

    protected static ?string $title = 'Acerca de';

    protected static ?string $slug = 'acerca-de';

    protected string $view = 'filament.pages.about';

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        return SystemDeclaration::data();
    }
}
