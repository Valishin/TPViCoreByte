<?php

namespace App\Filament\Pages;

use App\Models\FiscalRecord;
use App\Models\FiscalRecordState;
use App\Models\Setting;
use App\Services\Verifactu\CertificateStore;
use App\Services\Verifactu\ChainVerifier;
use App\Services\Verifactu\CorrectionService;
use App\Services\Verifactu\EmissionGuard;
use App\Services\Verifactu\Reconciler;
use App\Services\Verifactu\Verifactu as VerifactuState;
use App\Services\Verifactu\VerifactuSender;
use App\Services\Verifactu\VerifactuStatus;
use App\Support\Format;
use App\Support\TaxId;
use App\Support\VerifactuAlarm;
use BackedEnum;
use DOMDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * VERI*FACTU: certificado, entorno y régimen de IVA (solo admin). El estado de
 * los envíos y de cada registro se muestra en la misma página (docs/verifactu/PLAN.md).
 *
 * @property-read Schema $form
 */
class Verifactu extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'VERI*FACTU';

    protected static ?string $title = 'VERI*FACTU';

    protected static ?string $slug = 'verifactu';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $setting = Setting::query()->first();

        return $setting !== null && (bool) auth()->user()?->can('update', $setting);
    }

    public function mount(): void
    {
        $this->form->fill(Setting::current()->only(['verifactu_environment', 'vat_regime_key']));
    }

    public function form(Schema $schema): Schema
    {
        $settings = Setting::current();

        $status = VerifactuStatus::summary($settings);

        return $schema->statePath('data')->columns(2)->components([
            Section::make('Estado')->columns(4)->columnSpanFull()->schema([
                TextEntry::make('st_active')->label('VERI*FACTU')->badge()
                    ->state($status['active'] ? 'Activo' : ($status['enabled'] ? 'Programado' : 'No activo'))
                    ->color($status['active'] ? 'success' : 'gray')
                    ->helperText(fn () => $status['started_at'] ? 'Desde el '.$status['started_at']->format('d/m/Y H:i') : null),
                TextEntry::make('st_env')->label('Entorno')->state($status['environment'] === 'produccion' ? 'Producción' : 'Pruebas'),
                TextEntry::make('st_pending')->label('Pendientes de enviar')->state($status['pending'])
                    ->color($status['pending'] ? 'warning' : null)
                    ->helperText(fn () => $status['incident_since'] ? 'Incidencia desde el '.$status['incident_since']->format('d/m/Y H:i') : null),
                TextEntry::make('st_attention')->label('Rechazados o por subsanar')->state($status['needs_attention'])->color($status['needs_attention'] ? 'danger' : null),
                TextEntry::make('st_last')->label('Último envío correcto')->state($status['last_success_at']?->format('d/m/Y H:i:s') ?? '—'),
                TextEntry::make('st_wait')->label('Espera entre envíos (AEAT)')->state($status['wait_seconds'].' s'),
                TextEntry::make('st_first')->label('Primer envío efectivo')->state($status['first_sent_at']?->format('d/m/Y') ?? '—')
                    ->helperText('Desde él, VERI*FACTU se mantiene al menos hasta el 31 de diciembre de ese año (RD 1007/2023, art. 16.5).'),
                TextEntry::make('st_end')->label('Renuncia (FechaFinVeriFactu)')->state($status['end_date']?->format('d/m/Y') ?? '—'),
            ]),
            Section::make('Certificado electrónico')
                ->description('Certificado cualificado con el que se envían los registros a la AEAT (TLS mutuo). Se guarda cifrado con la APP_KEY de esta instalación: sin ella, una copia de seguridad no permite recuperarlo.')
                ->columns(3)->columnSpanFull()
                ->schema([
                    TextEntry::make('cert_subject')->label('Titular')->state($settings->verifactu_certificate_subject ?? 'Sin certificado'),
                    TextEntry::make('cert_nif')->label('NIF del certificado')->state($settings->verifactu_certificate_nif ?? '—'),
                    TextEntry::make('cert_valid_to')->label('Válido hasta')
                        ->state(fn () => $settings->verifactu_certificate_valid_to?->format('d/m/Y') ?? '—')
                        ->color(fn () => (CertificateStore::daysToExpiry($settings) ?? 99) <= 30 ? 'danger' : null)
                        ->helperText(fn () => ($d = CertificateStore::daysToExpiry($settings)) !== null && $d <= 30 ? ($d < 0 ? 'Caducado: no se puede enviar.' : "Caduca en {$d} días: renuévalo.") : null),
                    TextEntry::make('cert_mode')->label('Modo')->state(fn () => $settings->verifactu_certificate_mode === CertificateStore::MODE_REPRESENTATIVE ? 'Representante (apoderado o colaborador social)' : 'Del propio obligado'),
                    TextEntry::make('cert_kind')->label('Tipo')->state(fn () => $settings->verifactu_certificate_kind === CertificateStore::KIND_SEAL ? 'Sello' : 'Personal'),
                ]),
            Section::make('Envío')->columns(2)->columnSpanFull()->schema([
                Select::make('verifactu_environment')->label('Entorno de la AEAT')->required()
                    ->options(['pruebas' => 'Pruebas (portal de pruebas externas)', 'produccion' => 'Producción'])
                    ->helperText('Por defecto, pruebas. En producción los registros tienen efectos fiscales.'),
                Select::make('vat_regime_key')->label('Régimen de IVA (clave de régimen)')->required()
                    ->options(['01' => '01 · Régimen general', '20' => '20 · Régimen simplificado (módulos)'])
                    ->helperText('Se informa en cada registro (RD 1007/2023, art. 10.1.m). Confírmalo con el cliente o su gestor.'),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Guardar')->submit('save')])]),
            Section::make('Registros de facturación')
                ->description('Cada registro con su huella, el estado en la AEAT y el XML enviado y recibido.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        Setting::current()->update([
            'verifactu_environment' => $data['verifactu_environment'] === 'produccion' ? 'produccion' : 'pruebas',
            // Las claves numéricas de las opciones llegan como entero (20): se normalizan a 2 cifras.
            'vat_regime_key' => in_array($key = str_pad((string) $data['vat_regime_key'], 2, '0', STR_PAD_LEFT), ['01', '20'], true) ? $key : '01',
        ]);
        Notification::make()->success()->title('Guardado')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->activateAction(),
            $this->renounceAction(),
            Action::make('sendNow')->label('Enviar pendientes')->icon('heroicon-o-paper-airplane')->color('gray')
                ->action(fn () => Notification::make()->title(app(VerifactuSender::class)->run())->send()),
            Action::make('reconcile')->label('Conciliar con la AEAT')->icon('heroicon-o-arrows-right-left')->color('gray')
                ->action(function () {
                    try {
                        $r = app(Reconciler::class)->reconcile((int) now('Europe/Madrid')->format('Y'), (int) now('Europe/Madrid')->format('n'));
                    } catch (\RuntimeException $e) {
                        Notification::make()->danger()->title('No se ha podido consultar')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->{$r['discrepancies'] ? 'warning' : 'success'}()
                        ->title("Este mes: {$r['checked']} facturas, {$r['matched']} coinciden")
                        ->body(implode("\n", array_slice($r['discrepancies'], 0, 10)) ?: null)->send();
                }),
            Action::make('verifyChain')->label('Verificar cadena')->icon('heroicon-o-link')->color('gray')
                ->action(function () {
                    ['count' => $count, 'problems' => $problems] = ChainVerifier::verify();
                    if ($problems) {
                        VerifactuAlarm::raise('Cadena de registros rota: '.$problems[0], VerifactuAlarm::CHAIN);
                        Notification::make()->danger()->title('La cadena tiene problemas')->body(implode("\n", array_slice($problems, 0, 10)))->send();

                        return;
                    }
                    VerifactuAlarm::clear(VerifactuAlarm::CHAIN);
                    Notification::make()->success()->title("Cadena correcta: {$count} registros")->send();
                }),
            $this->installCertificateAction(),
        ];
    }

    /** Activar VERI*FACTU: desde ese momento cada documento genera su registro y se remite (RRSIF 15 y 16). */
    public function activateAction(): Action
    {
        return Action::make('activate')->label('Activar VERI*FACTU')->icon('heroicon-o-power')->color('success')
            ->visible(fn () => ! Setting::current()->verifactu_enabled)
            ->modalDescription('Cada ticket y factura generará su registro de facturación y se enviará a la AEAT. Desde el primer envío efectivo no se puede desactivar hasta el 31 de diciembre de ese año (RD 1007/2023, art. 16.5).')
            ->schema([DateTimePicker::make('starts_at')->label('Empieza')->default(now())->required()->minDate(now()->subMinute())->seconds(false)])
            ->action(function (array $data) {
                $settings = Setting::current();
                if (! CertificateStore::installed($settings)) {
                    Notification::make()->danger()->title('Sube antes el certificado electrónico.')->send();

                    return;
                }
                if (! TaxId::isValid($settings->tax_id)) {
                    Notification::make()->danger()->title('El NIF del local no es válido: revísalo en Ajustes del local.')->send();

                    return;
                }
                $settings->update(['verifactu_enabled' => true, 'verifactu_started_at' => $data['starts_at'], 'verifactu_end_date' => null]);
                Notification::make()->success()->title('VERI*FACTU activado')->send();
                $this->redirect(static::getUrl());
            });
    }

    /**
     * Renuncia (OM 17): sin envío efectivo todavía, se desactiva sin más; si ya
     * hubo envíos, sigue hasta el 31/12 de este año y se comunica en los envíos
     * (Cabecera/RemisionVoluntaria/FechaFinVeriFactu = 31-12-AAAA).
     */
    public function renounceAction(): Action
    {
        return Action::make('renounce')->label('Renunciar a VERI*FACTU')->icon('heroicon-o-no-symbol')->color('danger')
            ->visible(fn () => Setting::current()->verifactu_enabled && ! Setting::current()->verifactu_end_date)
            ->requiresConfirmation()
            ->modalDescription(function () {
                $settings = Setting::current();
                $text = $settings->verifactu_first_sent_at
                    ? 'Ya hubo envíos a la AEAT: seguirá funcionando como VERI*FACTU hasta el 31/12/'.now('Europe/Madrid')->year.' y la renuncia se comunicará en los envíos (Orden HAC/1177/2024, art. 17).'
                    : 'Aún no hubo ningún envío efectivo: se desactiva ahora.';
                if (EmissionGuard::enforced()) {
                    $text .= ' Este sistema solo funciona como VERI*FACTU: cuando deje de serlo, desde la fecha de obligación ('.VerifactuState::obligationDate()->format('d/m/Y').') no podrá emitir tickets ni facturas.';
                }

                return $text;
            })
            ->action(function () {
                $settings = Setting::current();
                if (! $settings->verifactu_first_sent_at) {
                    $settings->update(['verifactu_enabled' => false, 'verifactu_started_at' => null]);
                    Notification::make()->success()->title('VERI*FACTU desactivado')->send();
                } else {
                    $settings->update(['verifactu_end_date' => now('Europe/Madrid')->endOfYear()->toDateString()]);
                    Notification::make()->warning()->title('Renuncia comunicada: VERI*FACTU hasta el 31/12/'.now('Europe/Madrid')->year)->send();
                }
                $this->redirect(static::getUrl());
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(FiscalRecord::query()->with(['state.submission', 'ticket']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('Nº')->sortable(),
                TextColumn::make('generated_at')->label('Generado'),
                TextColumn::make('record_type')->label('Tipo')->badge()
                    ->formatStateUsing(fn (string $state, FiscalRecord $r) => $state === FiscalRecord::ALTA ? 'Alta '.$r->invoice_type.($r->is_correction ? ' (subsanación)' : '') : 'Anulación'),
                TextColumn::make('invoice_number')->label('Factura')->searchable(),
                TextColumn::make('amount_total')->label('Importe')->formatStateUsing(fn ($state) => $state === null ? '—' : Format::money((int) $state)),
                TextColumn::make('state.status')->label('Estado')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        FiscalRecordState::ACCEPTED => 'success',
                        FiscalRecordState::ACCEPTED_WITH_ERRORS => 'warning',
                        FiscalRecordState::REJECTED, FiscalRecordState::SEND_ERROR => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('state.error_message')->label('Error')->limit(60)->placeholder('—')
                    ->tooltip(fn (FiscalRecord $r) => $r->state?->error_code ? '['.$r->state->error_code.'] '.$r->state->error_message : null),
                TextColumn::make('state.attempts')->label('Intentos'),
                TextColumn::make('state.aeat_status')->label('En la AEAT (consulta)')->placeholder('—')->toggleable(),
                TextColumn::make('state.submission.csv')->label('CSV')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options([
                    FiscalRecordState::PENDING => 'Pendiente', FiscalRecordState::SENT => 'Enviado', FiscalRecordState::ACCEPTED => 'Correcto',
                    FiscalRecordState::ACCEPTED_WITH_ERRORS => 'Aceptado con errores', FiscalRecordState::REJECTED => 'Incorrecto', FiscalRecordState::SEND_ERROR => 'Error de envío',
                ])->query(fn ($query, array $data) => $data['value'] ? $query->whereHas('state', fn ($q) => $q->where('status', $data['value'])) : $query),
            ])
            ->recordActions([
                Action::make('detail')->label('Ver')->icon('heroicon-o-eye')->color('gray')
                    ->modalHeading(fn (FiscalRecord $r) => "Registro {$r->id} · {$r->invoice_number}")
                    ->modalSubmitAction(false)->modalWidth('5xl')
                    ->schema(fn (FiscalRecord $r) => [
                        TextEntry::make('hash')->label('Huella')->state($r->hash)->copyable(),
                        TextEntry::make('prev')->label('Huella del registro anterior')->state($r->previous_hash ?? 'Primer registro'),
                        TextEntry::make('xml')->label('XML del registro (enviado)')->state(new HtmlString('<pre style="white-space:pre-wrap;font-size:11px">'.e(self::pretty($r->xml)).'</pre>')),
                        TextEntry::make('response')->label('Último envío: respuesta de la AEAT')
                            ->state(new HtmlString('<pre style="white-space:pre-wrap;font-size:11px">'.e(self::pretty((string) $r->state?->submission?->response_xml) ?: 'Sin respuesta').'</pre>')),
                    ]),
                Action::make('correct')->label('Subsanar y reenviar')->icon('heroicon-o-wrench')->color('warning')
                    ->visible(fn (FiscalRecord $r) => (bool) $r->state?->needs_correction && ! $r->state?->corrected_by_record_id)
                    ->requiresConfirmation()
                    ->modalDescription('Se genera un registro de subsanación con los datos correctos de la misma factura (no cambia la factura). Solo si no procede una rectificativa ni una anulación.')
                    ->action(function (FiscalRecord $r) {
                        try {
                            $new = app(CorrectionService::class)->correct($r);
                        } catch (\LogicException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title("Subsanación generada (registro {$new->id})")->send();
                    }),
                Action::make('retry')->label('Reintentar')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible(fn (FiscalRecord $r) => $r->state?->status === FiscalRecordState::SEND_ERROR)
                    ->action(function (FiscalRecord $r) {
                        $r->state->update(['status' => FiscalRecordState::PENDING, 'error_message' => null]);
                        Notification::make()->success()->title('Vuelve a la cola de envío')->send();
                    }),
            ]);
    }

    private static function pretty(string $xml): string
    {
        if (trim($xml) === '') {
            return '';
        }
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        return @$doc->loadXML($xml) ? (string) $doc->saveXML($doc->documentElement) : $xml;
    }

    public function installCertificateAction(): Action
    {
        return Action::make('installCertificate')
            ->label(fn () => CertificateStore::installed() ? 'Cambiar certificado' : 'Subir certificado')
            ->icon('heroicon-o-key')
            ->modalHeading('Certificado electrónico (.p12 / .pfx)')
            ->schema([
                FileUpload::make('file')->label('Fichero .p12 o .pfx')->required()->storeFiles(false)
                    ->acceptedFileTypes(['application/x-pkcs12', 'application/pkcs12', 'application/octet-stream'])->maxSize(64),
                TextInput::make('password')->label('Contraseña del certificado')->password()->revealable()->required(),
                Radio::make('mode')->label('De quién es')->required()->default(CertificateStore::MODE_OBLIGED)->options([
                    CertificateStore::MODE_OBLIGED => 'Del propio obligado (el NIF debe ser el del local)',
                    CertificateStore::MODE_REPRESENTATIVE => 'De un representante (apoderado o colaborador social)',
                ]),
                Radio::make('kind')->label('Tipo')->required()->default(CertificateStore::KIND_PERSONAL)->inline()->options([
                    CertificateStore::KIND_PERSONAL => 'Personal o de representante de entidad',
                    CertificateStore::KIND_SEAL => 'Sello',
                ]),
            ])
            ->action(function (array $data) {
                $file = $data['file'];
                $file = is_array($file) ? reset($file) : $file;
                $content = $file instanceof TemporaryUploadedFile ? $file->get() : null;
                try {
                    $info = app(CertificateStore::class)->import((string) $content, (string) $data['password'], $data['mode'], $data['kind']);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Certificado no instalado')->body($e->getMessage())->send();

                    return;
                } finally {
                    if ($file instanceof TemporaryUploadedFile) {
                        $file->delete();
                    }
                }
                Notification::make()->success()->title('Certificado instalado')
                    ->body("{$info['subject']} · NIF {$info['nif']} · válido hasta el {$info['valid_to']->format('d/m/Y')}")->send();
                $this->redirect(static::getUrl());
            });
    }

    public static function stateLabel(): string
    {
        return VerifactuState::active() ? 'Activo' : 'No activo';
    }
}
