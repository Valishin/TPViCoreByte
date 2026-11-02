<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Models\DeliveryZone;
use App\Support\PostalCode;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    protected static ?string $title = 'Direcciones';

    protected static ?string $modelLabel = 'dirección';

    protected static ?string $pluralModelLabel = 'direcciones';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('label')->label('Etiqueta')->datalist(['Casa', 'Trabajo'])->maxLength(50),
            TextInput::make('street')->label('Calle')->required()->maxLength(255)->columnSpan(2),
            TextInput::make('number')->label('Número')->maxLength(20),
            TextInput::make('floor_door')->label('Piso / puerta')->maxLength(50),
            TextInput::make('postal_code')->label('Código postal')->maxLength(10)->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    // Sugerir la zona y el municipio por código postal.
                    $zone = DeliveryZone::query()->whereJsonContains('postal_codes', $state)->first();
                    if ($zone) {
                        $set('delivery_zone_id', $zone->id);
                    }
                    if (blank($get('city')) && ($city = PostalCode::city($state))) {
                        $set('city', $city);
                    }
                }),
            TextInput::make('city')->label('Municipio')->maxLength(255)
                ->helperText(fn (Get $get) => ($province = PostalCode::province($get('postal_code'))) ? "Provincia: {$province}" : null),
            Select::make('delivery_zone_id')->label('Zona de reparto')->relationship('deliveryZone', 'name')
                ->helperText('Se rellena sola si el código postal está en una zona.'),
            Toggle::make('is_default')->label('Dirección principal')->inline(false),
            Textarea::make('instructions')->label('Indicaciones para el repartidor')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('street')
            ->columns([
                TextColumn::make('label')->label('Etiqueta')->placeholder('—'),
                TextColumn::make('street')->label('Dirección')
                    ->formatStateUsing(fn ($record) => trim("{$record->street} {$record->number}, {$record->floor_door}", ', '))
                    ->description(fn ($record) => trim("{$record->postal_code} {$record->city}")),
                TextColumn::make('deliveryZone.name')->label('Zona')->placeholder('Sin zona'),
                IconColumn::make('is_default')->label('Principal')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->getOwnerRecord()->anonymized_at;
    }
}
