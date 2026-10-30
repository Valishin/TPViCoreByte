<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\Verifactu\CertificateStore;
use App\Services\Verifactu\Verifactu as VerifactuState;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * VERI*FACTU: certificado, entorno y régimen de IVA (solo admin). El estado de
 * los envíos y de cada registro se muestra en la misma página (docs/verifactu/PLAN.md).
 *
 * @property-read Schema $form
 */
class Verifactu extends Page
{
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

        return $schema->statePath('data')->columns(2)->components([
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
        return [$this->installCertificateAction()];
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
