<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\Fiscal\FiscalExportService;
use App\Support\AllowedInReadOnly;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Exportación de datos fiscales (ZIP con CSV y PDF). Solo admin.
 * Debe seguir disponible en el futuro modo solo consulta.
 *
 * @property-read Schema $form
 */
#[AllowedInReadOnly]
class FiscalExport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Exportación fiscal';

    protected static ?string $title = 'Exportación fiscal';

    protected static ?string $slug = 'exportacion-fiscal';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $setting = Setting::query()->first();

        return $setting !== null && (bool) auth()->user()?->can('update', $setting); // solo admin
    }

    public function mount(): void
    {
        $this->form->fill(['from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make()
                ->description('Tickets (con líneas y desglose de IVA), rectificativos y cierres Z del periodo, en CSV (para la gestoría o una hoja de cálculo) y PDF, dentro de un ZIP.')
                ->columns(2)
                ->schema([
                    DatePicker::make('from')->label('Desde')->required()->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('to')->label('Hasta')->required()->native(false)->displayFormat('d/m/Y')->afterOrEqual('from'),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('export')
                ->footer([Actions::make([
                    Action::make('export')->label('Descargar ZIP')->icon('heroicon-o-arrow-down-tray')->submit('export'),
                ])]),
        ]);
    }

    public function export()
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();

        $path = app(FiscalExportService::class)->export(Carbon::parse($data['from']), Carbon::parse($data['to']));

        return response()->download($path)->deleteFileAfterSend();
    }
}
