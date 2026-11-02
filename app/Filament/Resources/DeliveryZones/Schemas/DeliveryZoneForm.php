<?php

namespace App\Filament\Resources\DeliveryZones\Schemas;

use App\Filament\Components\MoneyInput;
use App\Models\DeliveryZone;
use App\Support\PostalCode;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class DeliveryZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Nombre de la zona')->required()->maxLength(255),
                Toggle::make('is_active')->label('Activa')->default(true)->inline(false),
                MoneyInput::make('delivery_fee')->label('Gastos de envío')->default(0)->required(),
                MoneyInput::make('minimum_order')->label('Pedido mínimo')->default(0)->required(),
            ]),
            // Cada código postal con su municipio: sale en la dirección y en el ticket de domicilio
            // («17220 Sant Feliu de Guíxols (Girona)»). La provincia sale sola del código.
            Section::make('Códigos postales')->columnSpanFull()->schema([
                Repeater::make('areas')->hiddenLabel()
                    ->addActionLabel('Añadir código postal')
                    ->defaultItems(0)
                    ->table([
                        TableColumn::make('Código postal')->markAsRequired()->width('160px'),
                        TableColumn::make('Municipio')->markAsRequired(),
                        TableColumn::make('Provincia')->width('220px'),
                    ])
                    ->compact()
                    ->schema([
                        TextInput::make('postal_code')->label('Código postal')->required()->distinct()
                            ->regex('/^\d{5}$/')->validationMessages(['regex' => '5 cifras.'])
                            ->maxLength(5)->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                                if (blank($get('city')) && ($city = PostalCode::city($state))) {
                                    $set('city', $city);
                                }
                            }),
                        TextInput::make('city')->label('Municipio')->required()->maxLength(255)->placeholder('Sant Feliu de Guíxols'),
                        TextInput::make('province')->label('Provincia')->disabled()->dehydrated(false)
                            ->formatStateUsing(fn (Get $get) => PostalCode::province($get('postal_code')))
                            ->placeholder(fn (Get $get) => PostalCode::province($get('postal_code')) ?? '—'),
                    ]),
            ]),
        ]);
    }

    /** Del registro al formulario: una fila por código postal con su municipio. */
    public static function toForm(array $data): array
    {
        $cities = $data['postal_code_cities'] ?? [];
        $data['areas'] = array_map(fn ($cp) => ['postal_code' => (string) $cp, 'city' => $cities[(string) $cp] ?? null], $data['postal_codes'] ?? []);

        return $data;
    }

    /** Del formulario al registro: postal_codes (para buscar la zona) y postal_code_cities. */
    public static function fromForm(array $data): array
    {
        $rows = collect($data['areas'] ?? [])->filter(fn ($row) => filled($row['postal_code'] ?? null));
        $data['postal_codes'] = $rows->pluck('postal_code')->map(fn ($cp) => trim((string) $cp))->values()->all();
        $data['postal_code_cities'] = $rows->mapWithKeys(fn ($row) => [trim((string) $row['postal_code']) => trim((string) ($row['city'] ?? ''))])
            ->filter()->all() ?: null;
        unset($data['areas']);

        return $data;
    }

    /** «17220 Sant Feliu de Guíxols» para la lista de zonas. */
    public static function labels(DeliveryZone $zone): array
    {
        return array_map(fn ($cp) => trim($cp.' '.$zone->cityFor((string) $cp)), $zone->postal_codes ?? []);
    }
}
