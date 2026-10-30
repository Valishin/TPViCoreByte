<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\TaxIdType;
use App\Models\Customer;
use App\Models\Setting;
use App\Rules\TaxIdRule;
use App\Support\PhoneNormalizer;
use App\Support\TaxId;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('phone')->label('Teléfono')->tel()->maxLength(20)
                    // Obligatorio salvo en clientes solo de facturación (con NIF).
                    ->requiredWithout('tax_id')
                    ->validationMessages(['required_without' => 'Indica el teléfono o, si es solo de facturación, el NIF.'])
                    ->helperText('Se guarda normalizado (+34…): da igual cómo lo escribas.')
                    ->rule(fn (?Customer $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                        if (blank($value)) {
                            return;
                        }
                        $existing = Customer::findByPhone((string) $value);
                        if ($existing && ! $existing->is($record)) {
                            $fail("Ese teléfono ya es de {$existing->name}.");
                        } elseif (PhoneNormalizer::normalize((string) $value) === null) {
                            $fail('Escribe un teléfono válido.');
                        }
                    }),
                TextInput::make('name')->label('Nombre')->maxLength(255)
                    ->requiredWithout('legal_name')
                    ->helperText('Si se deja vacío, se usa la razón social.'),
                Textarea::make('notes')->label('Notas')->rows(3)->columnSpanFull()
                    ->placeholder('Alergias, timbre roto, prefiere que llamen…'),
            ])->hidden(fn (?Customer $record) => $record?->isAnonymized()),
            // Datos para facturas completas (art. 6.1.c, d, e RD 1619/2012).
            Section::make('Datos fiscales (facturas)')->columns(3)->columnSpanFull()
                ->description('Para emitirle facturas completas. Cada factura guarda su propia copia: cambiarlos aquí no altera las ya emitidas.')
                ->hidden(fn (?Customer $record) => $record?->isAnonymized())
                ->schema([
                    TextInput::make('legal_name')->label('Nombre y apellidos o razón social')->maxLength(255)->columnSpan(2)
                        ->requiredWith('tax_id'),
                    Select::make('tax_id_type')->label('Tipo de documento')->options(TaxIdType::class)
                        ->placeholder('Se deduce del número'),
                    TextInput::make('tax_id')->label('NIF / NIE / CIF / NIF-IVA')->maxLength(30)
                        ->requiredWith('legal_name')
                        ->rule(fn (Get $get) => new TaxIdRule(TaxIdType::tryFrom((string) ($get('tax_id_type') instanceof TaxIdType ? $get('tax_id_type')->value : $get('tax_id_type')))))
                        ->rule(fn (?Customer $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                            $existing = Customer::query()->where('tax_id', TaxId::normalize($value))->first();
                            if ($existing && ! $existing->is($record)) {
                                $fail("Ese NIF ya es de {$existing->legal_name}.");
                            }
                        }),
                    TextInput::make('fiscal_address')->label('Domicilio fiscal')->maxLength(255)->columnSpan(2)
                        ->required(fn (Get $get) => filled($get('tax_id')) && (Setting::query()->value('invoice_require_address') ?? true)),
                    TextInput::make('fiscal_postal_code')->label('Código postal')->maxLength(10)
                        ->required(fn (Get $get) => filled($get('tax_id')) && (Setting::query()->value('invoice_require_address') ?? true)),
                    TextInput::make('fiscal_city')->label('Población')->maxLength(255)
                        ->required(fn (Get $get) => filled($get('tax_id')) && (Setting::query()->value('invoice_require_address') ?? true)),
                    TextInput::make('fiscal_province')->label('Provincia')->maxLength(255),
                    TextInput::make('fiscal_country')->label('País (ISO, 2 letras)')->length(2)->placeholder('ES'),
                    TextInput::make('billing_email')->label('Email para facturas')->email()->maxLength(255)->columnSpan(2),
                    Toggle::make('email_invoices_accepted_at')->label('Acepta recibir las facturas por email')->inline(false)
                        ->helperText('Consentimiento para el envío electrónico (art. 9.2).')
                        ->formatStateUsing(fn ($state) => $state !== null)
                        ->dehydrateStateUsing(fn ($state, ?Customer $record) => $state ? ($record?->email_invoices_accepted_at ?? now()) : null),
                ]),
            Section::make('Historial')->columns(3)->columnSpanFull()->visibleOn('edit')->schema([
                TextEntry::make('orders_count')->label('Pedidos'),
                TextEntry::make('last_order_at')->label('Último pedido')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('anonymized_at')->label('Anonimizado el')->dateTime('d/m/Y H:i')->placeholder('No'),
            ]),
        ]);
    }
}
