<?php

namespace App\Filament\Pages;

use App\Enums\OrderType;
use App\Filament\Resources\Printers\PrinterResource;
use App\Filament\Resources\TicketSeries\SeriesNumbering;
use App\Models\Printer;
use App\Models\Setting;
use App\Services\Printing\UsbPrinterDetector;
use App\Services\Verifactu\CertificateStore;
use App\Services\Verifactu\Verifactu as VerifactuState;
use App\Support\InstallMode;
use App\Support\SetupProgress;
use App\Support\TailscaleBridge;
use App\Support\TaxId;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
                            $this->skipActions('empresa'),
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
                            // Primer número de cada serie (continuar la de un sistema anterior); se guarda con su propio botón.
                            SeriesNumbering::section(),
                            $this->skipActions('fiscal'),
                        ])
                        ->afterValidation(fn (Step $component) => $this->saveStep($component, 'fiscal', ['vat_regime_key', 'corporate_tax'])),

                    $this->step('pedidos', Heroicon::OutlinedClipboardDocumentList)
                        ->schema([
                            CheckboxList::make('enabled_order_types')->label('Tipos de pedido activos en este local')->required()->minItems(1)
                                ->options(collect(OrderType::cases())->mapWithKeys(fn (OrderType $t) => [$t->value => $t->label()])->all())
                                ->formatStateUsing(fn ($state) => collect($state ?? [])->map(fn ($t) => $t instanceof OrderType ? $t->value : $t)->all()),
                            $this->skipActions('pedidos'),
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
                            $this->skipActions('impresoras'),
                        ]),

                    $this->step('copias', Heroicon::OutlinedCloudArrowUp, 'Cifradas con age y subidas fuera del local.')
                        ->schema([...BackupSettings::fields($this), $this->skipActions('copias')])
                        ->afterValidation(fn () => $this->saveBackups()),

                    $this->step('remoto', Heroicon::OutlinedGlobeAlt, 'Soporte a distancia con Tailscale.')
                        ->schema([
                            TextEntry::make('tailscale_status')->label('Estado')->state(fn () => TailscaleBridge::describe()),
                            TextInput::make('tailscale_authkey')->label('Clave de Tailscale (de un solo uso)')->password()->revealable()
                                ->placeholder('tskey-auth-…')->dehydrated(false)
                                ->helperText('Panel de Tailscale → Settings → Keys → «Generate auth key» (de un solo uso). No se guarda: el equipo la usa y la borra.')
                                ->hidden(fn () => TailscaleBridge::connected()),
                            Toggle::make('skip_remote')->label('No usar acceso remoto por ahora')
                                ->hidden(fn () => TailscaleBridge::connected()),
                            TextEntry::make('tailscale_ssh_state')->label('Acceso SSH por Tailscale')
                                ->state(fn () => Setting::current()->tailscale_ssh ? 'Permitido (activado a mano)' : 'Desactivado'),
                            Actions::make([self::allowSshAction(), self::revokeSshAction()])->key('tailscale-ssh'),
                            $this->skipActions('remoto'),
                        ])
                        ->afterValidation(function () {
                            $key = trim((string) ($this->data['tailscale_authkey'] ?? ''));
                            if ($key !== '') {
                                try {
                                    TailscaleBridge::submit($key, 'tpv-'.Setting::current()->trade_name, (bool) Setting::current()->tailscale_ssh);
                                } catch (\InvalidArgumentException|\RuntimeException $e) {
                                    throw ValidationException::withMessages(['data.tailscale_authkey' => $e->getMessage()]);
                                } finally {
                                    $this->data['tailscale_authkey'] = null;
                                }
                                SetupProgress::mark('remoto', 'done');
                                Notification::make()->success()->title('Clave enviada: el equipo se une a Tailscale en unos segundos')->send();
                            } elseif ($this->data['skip_remote'] ?? false) {
                                SetupProgress::mark('remoto', 'skipped');
                            }
                        }),

                    $this->step('verifactu', Heroicon::OutlinedShieldCheck, 'Obligatorio desde la fecha del paso 2.')
                        ->schema([
                            TextEntry::make('cert_status')->label('Certificado electrónico')->state(fn () => self::certificateText())
                                ->hidden(fn () => InstallMode::isDemo()),
                            TextEntry::make('verifactu_status')->label('VERI*FACTU')->state(fn () => self::verifactuText()),
                            Text::make(new HtmlString('Sube el certificado (.p12 o .pfx) y elige «Activar ahora» o «Activar automáticamente en la fecha de obligación» en '
                                .'<a class="underline" href="'.e(Verifactu::getUrl()).'">VERI*FACTU</a>.'))->hidden(fn () => InstallMode::isDemo()),
                        ]),
                ])
                    ->key('puesta-en-marcha')
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
            ->key($key)
            ->icon($icon)
            ->completedIcon(Heroicon::OutlinedCheck)
            ->description(($complete ? '✓ Completo' : 'Pendiente').($description ? " · {$description}" : ''));
    }

    /**
     * «Omitir este paso»: pasa al siguiente sin validar ni guardar. El paso
     * sigue «Pendiente» (salvo el acceso remoto, que es opcional y queda
     * omitido) y se puede retomar cuando se quiera.
     */
    public function skipStep(string $key): void
    {
        $keys = array_keys(SetupProgress::STEPS);
        $index = array_search($key, $keys, true);
        if ($index === false || ! isset($keys[$index + 1])) {
            return;
        }
        if ($key === 'remoto') {
            SetupProgress::mark('remoto', 'skipped');
        }

        /** @var Wizard $wizard */
        $wizard = $this->form->getComponent(fn ($c) => $c instanceof Wizard);
        $steps = collect($wizard->getChildSchema()->getComponents())->whereInstanceOf(Step::class)->keyBy(fn (Step $step) => Str::afterLast($step->getKey(), '.'));
        $this->dispatch('next-wizard-step', key: $wizard->getKey(), step: $steps[$keys[$index + 1]]->getKey(), currentStep: $steps[$key]->getKey());

        Notification::make()->info()->title(SetupProgress::STEPS[$key].': omitido')
            ->body($key === 'remoto' ? 'Lo puedes configurar más adelante.' : 'Queda pendiente: puedes volver a este paso cuando quieras.')->send();
    }

    /** Botón «Omitir este paso» al pie de un paso. */
    private function skipActions(string $key): Actions
    {
        return Actions::make([
            Action::make("omitir_{$key}")->label('Omitir este paso')->icon(Heroicon::OutlinedForward)
                ->link()->color('gray')
                ->action(fn () => $this->skipStep($key)),
        ])->key("omitir-{$key}")->alignEnd()->columnSpanFull();
    }

    public const SSH_WARNING = 'Con el SSH de Tailscale, las personas y equipos de tu red Tailscale a los que las ACL lo permitan podrán abrir '
        .'una consola en este mini PC sin su contraseña, con el usuario que digan las ACL (también root). Quien tenga esa consola '
        .'puede ver y cambiar todo: ventas, tickets, clientes, certificado y copias. Actívalo solo para dar soporte por SSH y con '
        .'las ACL limitadas a tus dispositivos; quítalo cuando no haga falta. El SSH normal por la red local no cambia.';

    /** «Permitir acceso SSH por Tailscale»: solo a mano y con el aviso de lo que implica. */
    public static function allowSshAction(): Action
    {
        return Action::make('allowTailscaleSsh')->label('Permitir acceso SSH por Tailscale')->icon(Heroicon::OutlinedCommandLine)->color('danger')
            ->visible(fn () => ! Setting::current()->tailscale_ssh)
            ->requiresConfirmation()
            ->modalHeading('¿Permitir acceso SSH por Tailscale?')
            ->modalDescription(self::SSH_WARNING)
            ->modalSubmitActionLabel('Sí, permitir SSH')
            ->schema([Checkbox::make('understood')->label('Entiendo lo que implica')->accepted()])
            ->action(fn () => self::setSsh(true));
    }

    public static function revokeSshAction(): Action
    {
        return Action::make('revokeTailscaleSsh')->label('Quitar acceso SSH por Tailscale')->icon(Heroicon::OutlinedNoSymbol)->color('gray')
            ->visible(fn () => (bool) Setting::current()->tailscale_ssh)
            ->action(fn () => self::setSsh(false));
    }

    private static function setSsh(bool $on): void
    {
        Setting::current()->update(['tailscale_ssh' => $on]);
        $applied = TailscaleBridge::connected() || TailscaleBridge::pending();
        if ($applied) {
            TailscaleBridge::requestSsh($on);
        }
        Notification::make()->{$on ? 'warning' : 'success'}()
            ->title($on ? 'Acceso SSH por Tailscale permitido' : 'Acceso SSH por Tailscale quitado')
            ->body($applied ? 'El equipo lo aplica en unos segundos.' : 'Se aplicará al unir el equipo a Tailscale.')->send();
    }

    public static function certificateText(): string
    {
        $s = Setting::current();
        if (! CertificateStore::installed($s)) {
            return 'Sin certificado (se puede subir cuando quieras; es obligatorio desde el '.VerifactuState::obligationDate($s)->format('d/m/Y').').';
        }
        $days = CertificateStore::daysToExpiry($s);

        return "{$s->verifactu_certificate_subject} · NIF {$s->verifactu_certificate_nif} · ".($days < 0 ? 'CADUCADO el ' : 'válido hasta el ')
            .$s->verifactu_certificate_valid_to?->format('d/m/Y');
    }

    public static function verifactuText(): string
    {
        $s = Setting::current();
        if (InstallMode::isDemo()) {
            return 'Instalación de demostración: VERI*FACTU queda apagado.';
        }
        if (! $s->verifactu_enabled || ! $s->verifactu_started_at) {
            return 'No activado.';
        }

        return VerifactuState::active($s) ? 'Activo desde el '.$s->verifactu_started_at->format('d/m/Y H:i')
            : 'Se activará automáticamente el '.$s->verifactu_started_at->format('d/m/Y').'.';
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
