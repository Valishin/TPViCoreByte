<?php

namespace App\Filament\Pages;

use App\Jobs\RunBackup;
use App\Models\BackupRun;
use App\Models\Setting;
use App\Services\Backup\BackupDestination;
use App\Services\Backup\BackupDisk;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Copias de seguridad: clave pública age y destino remoto (S3 compatible o
 * SFTP con clave generada aquí), y «Probar copia». También es el paso 5 de la
 * puesta en marcha (mismos campos y acciones).
 *
 * @property-read Schema $form
 */
class BackupSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Copias de seguridad';

    protected static ?string $title = 'Copias de seguridad';

    protected static ?string $slug = 'copias';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return LocalSettings::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill(self::fillData());
    }

    /** Datos del formulario (sin secretos: se dejan en blanco y se conservan si no se escriben). */
    public static function fillData(): array
    {
        return Setting::current()->only(self::FIELDS);
    }

    public const FIELDS = ['backup_age_recipient', 'backup_remote_type', 'backup_s3_endpoint', 'backup_s3_region', 'backup_s3_bucket',
        'backup_s3_access_key', 'backup_sftp_host', 'backup_sftp_port', 'backup_sftp_user', 'backup_path', 'backup_disk_uuid'];

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components(self::fields($this));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Guardar')->submit('save')])]),
        ]);
    }

    /** Campos y acciones (los usa también el asistente; $page guarda antes de probar). */
    public static function fields(Page $page): array
    {
        $type = fn (Get $get) => $get('backup_remote_type');

        return [
            Section::make('Cifrado')->schema([
                TextInput::make('backup_age_recipient')->label('Clave pública age')->required()
                    ->placeholder('age1…')->startsWith(['age1'])
                    ->validationMessages(['starts_with' => 'La clave pública de age empieza por «age1».'])
                    ->helperText('La privada NO se guarda en este equipo: guárdala tú en dos sitios. Sin ella no se pueden leer las copias.'),
            ]),
            Section::make('Destino remoto')->columns(2)->schema([
                // Con un disco aparte elegido, el destino remoto es opcional (mejor los dos).
                ToggleButtons::make('backup_remote_type')->label('Tipo')->inline()->live()->columnSpanFull()
                    ->required(fn (Get $get) => blank($get('backup_disk_uuid')))
                    ->options(['s3' => 'S3 compatible', 'sftp' => 'Servidor SFTP']),
                TextInput::make('backup_s3_endpoint')->label('Endpoint')->url()->placeholder('https://s3.eu-central-1.example.com')
                    ->visible(fn (Get $get) => $type($get) === 's3')->required(fn (Get $get) => $type($get) === 's3'),
                TextInput::make('backup_s3_region')->label('Región')->placeholder('eu-central-1')
                    ->visible(fn (Get $get) => $type($get) === 's3'),
                TextInput::make('backup_s3_bucket')->label('Bucket')
                    ->visible(fn (Get $get) => $type($get) === 's3')->required(fn (Get $get) => $type($get) === 's3'),
                TextInput::make('backup_s3_access_key')->label('Access key')
                    ->visible(fn (Get $get) => $type($get) === 's3')->required(fn (Get $get) => $type($get) === 's3'),
                TextInput::make('backup_s3_secret_key')->label('Secret key')->password()->revealable()->dehydrated(fn ($state) => filled($state))
                    ->placeholder(fn () => Setting::current()->backup_s3_secret_key ? '•••••• (guardada; escribe otra para cambiarla)' : null)
                    ->visible(fn (Get $get) => $type($get) === 's3')
                    ->required(fn (Get $get) => $type($get) === 's3' && ! Setting::current()->backup_s3_secret_key),
                TextInput::make('backup_sftp_host')->label('Servidor')->placeholder('copias.example.com')
                    ->visible(fn (Get $get) => $type($get) === 'sftp')->required(fn (Get $get) => $type($get) === 'sftp'),
                TextInput::make('backup_sftp_port')->label('Puerto')->numeric()->integer()->placeholder('22')
                    ->visible(fn (Get $get) => $type($get) === 'sftp'),
                TextInput::make('backup_sftp_user')->label('Usuario')
                    ->visible(fn (Get $get) => $type($get) === 'sftp')->required(fn (Get $get) => $type($get) === 'sftp'),
                TextInput::make('backup_path')->label('Carpeta en el destino')->placeholder('tpv-copias/nombre-del-local'),
                Textarea::make('sftp_public_key')->label('Clave pública SSH de este equipo')->dehydrated(false)->readOnly()->rows(2)
                    ->columnSpanFull()
                    ->formatStateUsing(fn () => Setting::current()->backup_sftp_public_key)
                    ->helperText('Añádela a ~/.ssh/authorized_keys del usuario en el servidor SFTP.')
                    ->visible(fn (Get $get) => $type($get) === 'sftp'),
                TextEntry::make('sftp_host_key')->label('Servidor verificado')->columnSpanFull()
                    ->state(fn () => Setting::current()->backup_sftp_known_hosts
                        ? 'Sí: rclone solo se conecta a este servidor ('.count(preg_split('/\R/', trim(Setting::current()->backup_sftp_known_hosts))).' claves guardadas).'
                        : 'No: pulsa «Obtener huella del servidor» y compárala con la del servidor antes de aceptarla.')
                    ->visible(fn (Get $get) => $type($get) === 'sftp'),
                Actions::make([
                    Action::make('scanHostKey')->label('Obtener huella del servidor')->icon(Heroicon::OutlinedFingerPrint)->color('gray')
                        ->modalHeading('Huella del servidor SFTP')
                        ->modalDescription('Compárala con la del servidor (en él: ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub). Acepta solo si coinciden.')
                        ->modalSubmitActionLabel('Coinciden: confiar en este servidor')
                        ->mountUsing(function (Schema $form, Get $get) use ($page) {
                            $page->saveBackups();
                            $s = Setting::current();
                            $scan = BackupDestination::scanSftpHost((string) $s->backup_sftp_host, (int) ($s->backup_sftp_port ?: 22));
                            $form->fill(['known_hosts' => $scan['known_hosts'], 'fingerprints' => implode("\n", $scan['fingerprints'])]);
                        })
                        ->schema([
                            Textarea::make('fingerprints')->label('Huellas')->readOnly()->rows(3),
                            Textarea::make('known_hosts')->hidden()->dehydratedWhenHidden(),
                        ])
                        ->action(function (array $data) {
                            Setting::current()->update(['backup_sftp_known_hosts' => $data['known_hosts']]);
                            Notification::make()->success()->title('Servidor SFTP verificado')->send();
                        }),
                    Action::make('generateSshKey')->label(fn () => Setting::current()->backup_sftp_public_key ? 'Generar otra clave SSH' : 'Generar clave SSH')
                        ->icon(Heroicon::OutlinedKey)->color('gray')
                        ->requiresConfirmation(fn () => (bool) Setting::current()->backup_sftp_public_key)
                        ->modalDescription('La clave anterior dejará de valer: tendrás que poner la nueva en el servidor.')
                        ->action(function (Set $set) {
                            $set('sftp_public_key', BackupDestination::generateSftpKey());
                            Notification::make()->success()->title('Clave SSH generada: cópiala en el servidor')->send();
                        }),
                ])->key('sftp-actions')->visible(fn (Get $get) => $type($get) === 'sftp')->columnSpanFull(),
            ]),
            Section::make('Copia en un disco aparte')
                ->description('Un disco USB conectado al mini PC: en cada cierre Z (y en cada copia) se guarda en él todo lo necesario '
                    .'—base de datos, claves y archivos, cifrado con la clave age— por si se estropea el disco o el equipo.')
                ->schema([
                    Select::make('backup_disk_uuid')->label('Disco')->placeholder('Ninguno')->live()
                        ->options(fn (Get $get) => self::diskOptions($get('backup_disk_uuid')))
                        ->helperText('Conéctalo por USB: sale aquí en unos segundos (ext4, exFAT, NTFS o FAT32). Si no sale, pulsa «Buscar discos». '
                            .'Si un día no está conectado, las copias se guardan en él en cuanto se vuelva a conectar.'),
                    TextEntry::make('disk_state')->hiddenLabel()->state(fn () => self::diskStateText()),
                    Actions::make([
                        Action::make('refreshDisks')->label('Buscar discos')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                            ->action(fn () => null),   // vuelve a leer los discos conectados
                        Action::make('copyToDisk')->label('Copiar ahora al disco')->icon(Heroicon::OutlinedArrowDownTray)
                            ->visible(fn (Get $get) => filled($get('backup_disk_uuid')))
                            ->action(function () use ($page) {
                                $page->saveBackups();
                                if (! BackupDisk::target()) {
                                    Notification::make()->warning()->title('El disco elegido no está conectado')->send();

                                    return;
                                }
                                RunBackup::dispatch('manual');
                                Notification::make()->success()->title('Copia en marcha')
                                    ->body('En unos segundos estará en el disco: el resultado aparece aquí.')->send();
                            }),
                    ])->key('backup-disk'),
                ]),
            Section::make('Comprobar')->schema([
                TextEntry::make('last_backup')->hiddenLabel()->state(fn () => self::lastRunText()),
                Actions::make([
                    Action::make('testBackup')->label('Probar copia')->icon(Heroicon::OutlinedPlay)
                        ->action(function () use ($page) {
                            $page->saveBackups();
                            if (! BackupDestination::configured() && ! (BackupDestination::recipient() && BackupDisk::chosen())) {
                                Notification::make()->warning()->title('Guarda primero la clave age y un destino: remoto (en SFTP, también la huella del servidor) o un disco aparte.')->send();

                                return;
                            }
                            RunBackup::dispatch('prueba');
                            Notification::make()->success()->title('Copia de prueba en marcha')
                                ->body('Tarda unos segundos: el resultado aparece aquí abajo.')->send();
                        }),
                ])->key('backup-test'),
            ]),
        ];
    }

    /** @return array<string, string> uuid → «COPIAS · EXFAT · 1 TB · libres 800 GB» */
    public static function diskOptions(?string $chosen = null): array
    {
        $options = collect(BackupDisk::available())->mapWithKeys(fn ($d) => [$d['uuid'] => BackupDisk::describe($d)])->all();
        $chosen ??= BackupDisk::chosen();
        if ($chosen && ! isset($options[$chosen])) {
            $options[$chosen] = 'Disco elegido (no conectado ahora)';
        }

        return $options;
    }

    public static function diskStateText(): string
    {
        if (! BackupDisk::chosen()) {
            return 'Sin disco aparte: las copias se guardan en este equipo'.(BackupDestination::remote() ? ' y en el destino remoto.' : '.');
        }
        $last = BackupRun::query()->where('disk_status', 'copiada')->latest('disk_at')->first();
        $connected = BackupDisk::target() !== null;

        return ($connected ? 'Disco conectado.' : 'El disco no está conectado ahora.')
            .($last ? ' Última copia en el disco: '.$last->disk_at->format('d/m/Y H:i').'.' : ' Todavía no se ha copiado nada en él.');
    }

    public static function lastRunText(): string
    {
        $run = BackupRun::query()->latest('id')->first();
        if (! $run) {
            return 'Todavía no se ha hecho ninguna copia.';
        }
        $when = $run->created_at->format('d/m/Y H:i');

        return match (true) {
            $run->status !== 'ok' => "Última copia ({$when}): ERROR · {$run->error}",
            $run->remote_status === 'subida' => "Última copia ({$when}): correcta y subida al destino ✓",
            $run->remote_status === 'error' => "Última copia ({$when}): hecha, pero NO se ha podido subir · {$run->error}",
            $run->remote_status === 'sin_destino' => "Última copia ({$when}): solo en este equipo (sin destino remoto).",
            default => "Última copia ({$when}): hecha, subiendo…",
        };
    }

    /** Lo usa «Probar copia»: guarda lo escrito antes de probar. */
    public function saveBackups(): void
    {
        $this->save(notify: false);
    }

    public function save(bool $notify = true): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->form->getState();
        Setting::current()->update(collect($state)->only([...self::FIELDS, 'backup_s3_secret_key'])->all());
        if ($notify) {
            Notification::make()->success()->title('Copias: guardado')->send();
        }
    }
}
