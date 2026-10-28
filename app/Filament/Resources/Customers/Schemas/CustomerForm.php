<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Support\PhoneNormalizer;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('phone')->label('Teléfono')->tel()->required()->maxLength(20)
                    ->helperText('Se guarda normalizado (+34…): da igual cómo lo escribas.')
                    ->rule(fn (?Customer $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                        $existing = Customer::findByPhone((string) $value);
                        if ($existing && ! $existing->is($record)) {
                            $fail("Ese teléfono ya es de {$existing->name}.");
                        } elseif (PhoneNormalizer::normalize((string) $value) === null) {
                            $fail('Escribe un teléfono válido.');
                        }
                    }),
                TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                Textarea::make('notes')->label('Notas')->rows(3)->columnSpanFull()
                    ->placeholder('Alergias, timbre roto, prefiere que llamen…'),
            ])->hidden(fn (?Customer $record) => $record?->isAnonymized()),
            Section::make('Historial')->columns(3)->columnSpanFull()->visibleOn('edit')->schema([
                TextEntry::make('orders_count')->label('Pedidos'),
                TextEntry::make('last_order_at')->label('Último pedido')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('anonymized_at')->label('Anonimizado el')->dateTime('d/m/Y H:i')->placeholder('No'),
            ]),
        ]);
    }
}
