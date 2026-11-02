<?php

namespace App\Filament\Pages;

use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderType;
use App\Filament\Components\MoneyInput;
use App\Filament\Components\VatRateSelect;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Models\Setting;
use App\Models\TicketSeries;
use App\Services\Billing\InvoiceMailer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;
use UnitEnum;

/**
 * Ajustes del local (una sola fila de `settings`). Solo admin.
 *
 * @property-read Schema $form
 */
class LocalSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Ajustes del local';

    protected static ?string $title = 'Ajustes del local';

    protected static ?string $slug = 'ajustes';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $setting = Setting::query()->first();

        return $setting !== null && (bool) auth()->user()?->can('update', $setting);
    }

    public function mount(): void
    {
        $this->form->fill(Setting::current()->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model(Setting::current())
            ->statePath('data')
            ->columns(2)
            ->components([
                Section::make('Datos fiscales')
                    ->description('Aparecen en todos los tickets. Cada ticket guarda una copia al emitirse.')
                    ->columns(2)->columnSpanFull()
                    ->schema([
                        TextInput::make('trade_name')->label('Nombre comercial')->required()->maxLength(255),
                        TextInput::make('legal_name')->label('Razón social')->required()->maxLength(255),
                        TextInput::make('tax_id')->label('NIF / CIF')->required()->maxLength(20)
                            ->regex('/^[A-Z0-9]{8,10}$/i')
                            ->validationMessages(['regex' => 'Escribe el NIF sin espacios ni guiones.']),
                        TextInput::make('phone')->label('Teléfono')->tel()->maxLength(20),
                        TextInput::make('address')->label('Dirección')->maxLength(255)->columnSpanFull(),
                        TextInput::make('postal_code')->label('Código postal')->maxLength(10),
                        TextInput::make('city')->label('Población')->maxLength(255),
                        TextInput::make('province')->label('Provincia')->maxLength(255),
                        TextInput::make('email')->label('Email')->email()->maxLength(255),
                    ]),
                Section::make('Ticket')->columns(2)->columnSpanFull()->schema([
                    FileUpload::make('logo_path')->label('Logo')
                        ->image()->disk('public')->directory('logo')->visibility('public')
                        ->maxSize(1024)
                        ->helperText('PNG o JPG en blanco y negro se imprime mejor. Máx. 1 MB.'),
                    Textarea::make('ticket_footer')->label('Pie del ticket')->rows(4)
                        ->placeholder("Gracias por su visita\nIVA incluido"),
                ]),
                Section::make('Tipos de pedido')->columnSpan(1)->schema([
                    CheckboxList::make('enabled_order_types')->label('Activos en este local')
                        ->options(OrderType::class)
                        ->required()
                        ->validationMessages(['required' => 'Activa al menos un tipo de pedido.']),
                ]),
                Section::make('Facturas con datos del cliente')->columns(2)->columnSpanFull()
                    ->description('Factura completa (RD 1619/2012): serie F para facturas y canjes de tickets, FR para sus rectificativas.')
                    ->schema([
                        Select::make('invoice_series_id')->label('Serie de facturas')
                            ->options(fn () => TicketSeries::query()->where('is_rectifying', false)->where('is_active', true)->pluck('name', 'id'))
                            ->required(),
                        Select::make('invoice_rectifying_series_id')->label('Serie de rectificativas de facturas')
                            ->options(fn () => TicketSeries::query()->where('is_rectifying', true)->where('is_active', true)->pluck('name', 'id'))
                            ->required(),
                        Toggle::make('invoice_require_address')->label('Domicilio del destinatario obligatorio')->inline(false)->columnSpanFull()
                            ->helperText('Activado: también para particulares (art. 6.1.e). Pendiente de confirmar con el asesor si los particulares pueden ir sin domicilio.'),
                    ]),
                Section::make('Correo saliente (facturas por email)')->columns(3)->columnSpanFull()
                    ->description('Para enviar el PDF de las facturas a los clientes que lo aceptan. La contraseña se guarda cifrada con APP_KEY.')
                    ->schema([
                        TextInput::make('smtp_host')->label('Servidor SMTP')->placeholder('smtp.ejemplo.com')->maxLength(255),
                        TextInput::make('smtp_port')->label('Puerto')->numeric()->integer()->placeholder('587'),
                        Select::make('smtp_encryption')->label('Cifrado')->options(['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'Ninguno'])
                            ->default('tls'),
                        TextInput::make('smtp_username')->label('Usuario')->maxLength(255),
                        TextInput::make('smtp_password')->label('Contraseña')->password()->revealable()
                            ->placeholder(fn () => filled(Setting::current()->smtp_password) ? '•••••• (guardada; escribe otra para cambiarla)' : '')
                            ->dehydrated(fn ($state) => filled($state)),
                        TextInput::make('mail_from_address')->label('Remitente (email)')->email()->maxLength(255),
                        TextInput::make('mail_from_name')->label('Remitente (nombre)')->maxLength(255)->placeholder('Nombre comercial'),
                    ]),
                Section::make('Numeración')->columnSpan(1)->schema([
                    Select::make('default_ticket_series_id')->label('Serie de tickets')
                        ->options(fn () => TicketSeries::query()->where('is_rectifying', false)->where('is_active', true)->pluck('name', 'id'))
                        ->required(),
                    Select::make('rectifying_ticket_series_id')->label('Serie de rectificativos')
                        ->options(fn () => TicketSeries::query()->where('is_rectifying', true)->where('is_active', true)->pluck('name', 'id'))
                        ->required(),
                ]),
                Section::make('Pizzas mitad y mitad')->columns(2)->columnSpanFull()->schema([
                    Radio::make('half_and_half_pricing')->label('Precio de la pizza')
                        ->options(HalfAndHalfPricing::class)->required(),
                    Radio::make('half_and_half_modifier_pricing')->label('Ingredientes extra en una mitad')
                        ->options([
                            HalfAndHalfModifierPricing::Full->value => 'Se cobran enteros',
                            HalfAndHalfModifierPricing::Half->value => 'Se cobran a mitad de precio',
                        ])->required(),
                ]),
                Section::make('TPV')->columns(3)->columnSpanFull()
                    ->description('Bloqueo automático: vuelve a la pantalla de PIN sin perder la comanda en curso.')
                    ->schema([
                        TextInput::make('auto_lock_minutes_pos')->label('Bloquear la caja tras (minutos)')
                            ->numeric()->integer()->minValue(0)->maxValue(120)->required()
                            ->helperText('0 = no bloquear nunca.'),
                        TextInput::make('auto_lock_minutes_mobile')->label('Bloquear los móviles tras (minutos)')
                            ->numeric()->integer()->minValue(0)->maxValue(120)->required()
                            ->helperText('0 = no bloquear nunca.'),
                        Toggle::make('lock_pos_after_send')->label('Bloquear la caja tras enviar una comanda')->inline(false),
                        Toggle::make('show_onscreen_keyboard_pos')->label('Mostrar teclado en pantalla en caja')->inline(false)
                            ->helperText('Desactivado, en caja solo se escribe con el teclado físico. En los móviles se usa siempre el del teléfono.'),
                        Section::make('Importes máximos')->columnSpanFull()->columns(4)
                            ->description('Por encima de estos importes el TPV pide confirmación con el importe en grande (evita un cero de más).')
                            ->schema([
                                MoneyInput::make('max_opening_float')->label('Fondo de caja inicial')->required(),
                                MoneyInput::make('max_cash_count')->label('Arqueo (efectivo contado)')->required(),
                                MoneyInput::make('max_cash_tendered')->label('Efectivo entregado al cobrar')->required(),
                                MoneyInput::make('max_tip')->label('Propina')->required(),
                            ]),
                        Toggle::make('admin_pos_link_new_tab')->label('«Ir a la TPV» abre otra pestaña')->inline(false)
                            ->helperText('Desactivado, la caja se abre en la misma pestaña de /admin.'),
                        Toggle::make('print_join_notice')->label('Avisar en cocina y barra al unir mesas')->inline(false)
                            ->helperText('Imprime «MESA 4 UNIDA A MESA 3» en las impresoras de cocina y barra. Lo ya enviado nunca se reimprime.'),
                    ]),
                Section::make('Notas rápidas generales')->columnSpanFull()
                    ->description('Salen en todos los productos, detrás de las notas de su categoría. Arrastra para ordenarlas.')
                    ->schema([CategoryForm::quickNotes()]),
                Section::make('Para llevar y domicilio')->columns(2)->columnSpanFull()
                    ->description('Tiempos para "Lo antes posible": la hora prometida que se propone al crear el pedido.')
                    ->schema([
                        TextInput::make('takeaway_prep_minutes')->label('Preparación para llevar (minutos)')
                            ->numeric()->integer()->minValue(1)->maxValue(240)->required(),
                        TextInput::make('delivery_minutes')->label('Preparación + reparto a domicilio (minutos)')
                            ->numeric()->integer()->minValue(1)->maxValue(240)->required(),
                        VatRateSelect::make('delivery_fee_vat_rate')->label('IVA de los gastos de envío')->required()
                            ->helperText('Normalmente el mismo que la comida (10 %).'),
                        Toggle::make('print_courier_copy')->label('Imprimir copia para el repartidor')->inline(false)
                            ->helperText('Al dar salida a un domicilio, una copia con la dirección y un QR que la abre en Google Maps.'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Guardar ajustes')->submit('save')->keyBindings(['mod+s']),
                        $this->sendTestEmailAction(),
                    ]),
                ]),
        ]);
    }

    /** Email de prueba con el SMTP guardado (a la dirección del remitente o a otra). */
    public function sendTestEmailAction(): Action
    {
        return Action::make('sendTestEmail')->label('Enviar email de prueba')->icon(Heroicon::OutlinedEnvelope)->color('gray')
            ->schema([TextInput::make('to')->label('Enviar a')->email()->required()->default(fn () => Setting::current()->mail_from_address)])
            ->action(function (array $data) {
                if (! InvoiceMailer::configured()) {
                    Notification::make()->warning()->title('Guarda primero el servidor SMTP y el remitente.')->send();

                    return;
                }
                try {
                    InvoiceMailer::configureMailer();
                    Mail::mailer(InvoiceMailer::MAILER)->raw('Email de prueba del TPV: el correo saliente funciona.', fn ($m) => $m->to($data['to'])->subject('Prueba del TPV'));
                    Notification::make()->success()->title("Enviado a {$data['to']}")->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('No se ha podido enviar')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        Setting::current()->update($this->form->getState());

        Notification::make()->success()->title('Ajustes guardados')->send();
    }
}
