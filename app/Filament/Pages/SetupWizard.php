<?php

namespace App\Filament\Pages;

use App\Enums\OrderType;
use App\Filament\Resources\Printers\PrinterResource;
use App\Models\Printer;
use App\Models\Setting;
use App\Services\Printing\UsbPrinterDetector;
use App\Support\InstallMode;
use App\Support\SetupProgress;
use App\Support\TaxId;
use BackedEnum;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Puesta en marcha: asistente de 7 pasos para dejar el local listo. Cada paso
 * se guarda al pulsar «Siguiente» y se puede dejar a medias; al volver se
 * empieza por el primero pendiente. Solo admin.
 *
 * @property-read Schema $form
 */
class SetupWizard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Puesta en marcha';

    protected static ?string $title = 'Puesta en marcha';

    protected static ?string $slug = 'puesta-en-marcha';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return LocalSettings::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $setting = Setting::query()->first();

        return $setting && ! SetupProgress::done($setting) ? SetupProgress::completed($setting).'/'.SetupProgress::total() : null;
    }

    public function mount(): void
    {
        $settings = Setting::current();
        if (! $settings->setup_seen_at) {
            $settings->update(['setup_seen_at' => now()]);
        }
        $this->form->fill($settings->attributesToArray() + [
            'skip_remote' => ($settings->setup_steps['remoto'] ?? null) === 'skipped',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model(Setting::current())
            ->statePath('data')
            ->components([
                Wizard::make([
                    $this->step('empresa', Heroicon::OutlinedBuildingStorefront, 'Salen en todos los tickets y facturas.')
                        ->columns(2)
                        ->schema([
                            TextInput::make('trade_name')->label('Nombre comercial')->required()->maxLength(255),
                            TextInput::make('legal_name')->label('Razón social')->required()->maxLength(255),
                            TextInput::make('tax_id')->label('NIF / CIF')->required()->maxLength(20)
                                ->dehydrateStateUsing(fn (?string $state) => TaxId::normalize($state))
                                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                                    if (! TaxId::isValidSpanish((string) $value)) {
                                        $fail(TaxId::error((string) $value) ?? 'Escribe un NIF, NIE o CIF español válido.');
                                    }
                                }),
                            TextInput::make('phone')->label('Teléfono')->tel()->maxLength(20),
                            TextInput::make('address')->label('Dirección')->required()->maxLength(255)->columnSpanFull(),
                            TextInput::make('postal_code')->label('Código postal')->required()->maxLength(10),
                            TextInput::make('city')->label('Población')->required()->maxLength(255),
                            TextInput::make('province')->label('Provincia')->maxLength(255),
                            FileUpload::make('logo_path')->label('Logo')
                                ->image()->disk('public')->directory('logo')->visibility('public')->maxSize(1024)
                                ->helperText('PNG o JPG; en blanco y negro se imprime mejor. Máx. 1 MB.'),
                            Textarea::make('ticket_footer')->label('Pie del ticket')->rows(3)
                                ->placeholder("Gracias por su visita\nIVA incluido"),
                        ])
                        ->afterValidation(fn (Step $component) => $this->saveStep($component, 'empresa',
                            ['trade_name', 'legal_name', 'tax_id', 'phone', 'address', 'postal_code', 'city', 'province', 'logo_path', 'ticket_footer'])),

                    $this->step('fiscal', Heroicon::OutlinedScale, 'Confírmalo con el cliente o su gestor.')
                        ->schema([
                            Radio::make('vat_regime_key')->label('Régimen de IVA')->required()
                                ->options(['01' => 'Régimen general', '20' => 'Régimen simplificado (módulos)'])
                                ->formatStateUsing(fn ($state) => $state === null ? null : str_pad((string) $state, 2, '0', STR_PAD_LEFT)),
                            Radio::make('corporate_tax')->label('¿Declara el Impuesto sobre Sociedades?')->required()->live()
                                ->boolean('Sí (sociedad)', 'No (autónomo o comunidad de bienes)')
                                ->helperText(fn ($state) => $state === null ? 'Decide desde cuándo es obligatorio VERI*FACTU.'
                                    : 'VERI*FACTU será obligatorio desde el '.SetupProgress::obligationLabel((bool) $state).'.'),
                        ])
                        ->afterValidation(fn (Step $component) => $this->saveStep($component, 'fiscal', ['vat_regime_key', 'corporate_tax'])),

                    $this->step('pedidos', Heroicon::OutlinedClipboardDocumentList)
                        ->schema([
                            CheckboxList::make('enabled_order_types')->label('Tipos de pedido activos en este local')->required()->minItems(1)
                                ->options(collect(OrderType::cases())->mapWithKeys(fn (OrderType $t) => [$t->value => $t->label()])->all())
                                ->formatStateUsing(fn ($state) => collect($state ?? [])->map(fn ($t) => $t instanceof OrderType ? $t->value : $t)->all()),
                        ])
                        ->afterValidation(function (Step $component) {
                            $this->saveStep($component, 'pedidos', ['enabled_order_types']);
                            SetupProgress::mark('pedidos');
                        }),

                    $this->step('impresoras', Heroicon::OutlinedPrinter, 'Al menos la de tickets.')
                        ->schema([
                            TextEntry::make('usb_detected')->label('Impresoras USB conectadas ahora')
                                ->state(fn () => collect((new UsbPrinterDetector)->detect())
                                    ->map(fn ($p) => "{$p['label']} → {$p['path']}".($p['configured'] ? ' (ya dada de alta)' : ''))
                                    ->whenEmpty(fn ($c) => $c->push('Ninguna (si es USB, conéctala y enciéndela).'))->all())
                                ->listWithLineBreaks(),
                            TextEntry::make('printers_configured')->label('Dadas de alta')
                                ->state(fn () => Printer::query()->orderBy('name')->get()
                                    ->map(fn (Printer $p) => "{$p->name} · {$p->type->getLabel()} · {$p->address()}".($p->is_active ? '' : ' (inactiva)'))
                                    ->whenEmpty(fn ($c) => $c->push('Ninguna todavía.'))->all())
                                ->listWithLineBreaks(),
                            Text::make(new HtmlString('Dalas de alta en <a class="underline" href="'.e(PrinterResource::getUrl('create')).'">Nueva impresora</a> '
                                .'(las USB se eligen de la lista de conectadas) y pulsa «Imprimir prueba» en '
                                .'<a class="underline" href="'.e(PrinterResource::getUrl()).'">Impresoras</a>.')),
                        ]),

                    $this->step('copias', Heroicon::OutlinedCloudArrowUp, 'Cifradas con age y subidas fuera del local.')
                        ->schema(BackupSettings::fields($this))
                        ->afterValidation(fn () => $this->saveBackups()),

                    $this->step('remoto', Heroicon::OutlinedGlobeAlt, 'Soporte a distancia con Tailscale.')
                        ->schema([
                            Toggle::make('skip_remote')->label('No usar acceso remoto por ahora'),
                        ])
                        ->afterValidation(function () {
                            if ($this->data['skip_remote'] ?? false) {
                                SetupProgress::mark('remoto', 'skipped');
                            }
                        }),

                    $this->step('verifactu', Heroicon::OutlinedShieldCheck)
                        ->schema([
                            Text::make(InstallMode::isDemo()
                                ? 'Instalación de demostración: VERI*FACTU queda apagado.'
                                : new HtmlString('Sube el certificado electrónico y elige cuándo activar VERI*FACTU: '
                                    .'<a class="underline" href="'.e(Verifactu::getUrl()).'">VERI*FACTU</a>.')),
                        ]),
                ])
                    ->startOnStep(fn () => SetupProgress::firstPending())
                    ->submitAction(new HtmlString('<span></span>')),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form')]);
    }

    /** Paso con su estado (completo / pendiente) bajo el título. */
    private function step(string $key, Heroicon $icon, ?string $description = null): Step
    {
        $complete = SetupProgress::steps()[$key]['complete'];

        return Step::make(SetupProgress::STEPS[$key])
            ->icon($icon)
            ->completedIcon(Heroicon::OutlinedCheck)
            ->description(($complete ? '✓ Completo' : 'Pendiente').($description ? " · {$description}" : ''));
    }

    /** Paso 5: guarda la clave age y el destino (también antes de «Probar copia»). */
    public function saveBackups(): void
    {
        $data = collect($this->data)->only([...BackupSettings::FIELDS, 'backup_s3_secret_key'])->filter(fn ($v, $k) => $k !== 'backup_s3_secret_key' || filled($v));
        Setting::current()->update($data->all());
    }

    /** Guarda los campos del paso (también el logo subido) al pulsar «Siguiente». */
    private function saveStep(Step $step, string $key, array $fields): void
    {
        $state = $step->getChildSchema()->getState();
        Setting::current()->update(Arr::only($state, $fields));
        Notification::make()->success()->title(SetupProgress::STEPS[$key].': guardado')->send();
    }
}
