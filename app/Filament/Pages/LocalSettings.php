<?php

namespace App\Filament\Pages;

use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderType;
use App\Filament\Components\VatRateSelect;
use App\Models\Setting;
use App\Models\TicketSeries;
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
                    ]),
                Section::make('Reparto')->columnSpanFull()->schema([
                    VatRateSelect::make('delivery_fee_vat_rate')->label('IVA de los gastos de envío')->required()
                        ->helperText('Normalmente el mismo que la comida (10 %).'),
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
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        Setting::current()->update($this->form->getState());

        Notification::make()->success()->title('Ajustes guardados')->send();
    }
}
