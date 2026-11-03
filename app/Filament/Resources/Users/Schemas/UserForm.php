<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                ToggleButtons::make('role')->label('Rol')->options(UserRole::class)->default(UserRole::Waiter)
                    ->inline()->required()->live(),
                TextInput::make('email')->label('Email')->email()->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->required(fn (Get $get) => self::backoffice($get))
                    ->helperText(fn (Get $get) => self::backoffice($get)
                        ? 'Obligatorio: lo usa para entrar en el backoffice.'
                        : 'Opcional para camareros y repartidores.'),
                TextInput::make('username')->label('Nombre de usuario')->maxLength(50)
                    ->regex('/^[A-Za-z0-9._-]{3,50}$/')
                    ->validationMessages(['regex' => 'De 3 a 50 caracteres: letras, números, punto, guion o guion bajo (sin espacios).'])
                    // Único sin distinguir mayúsculas (se guarda en minúsculas).
                    ->rule(fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                        $taken = User::query()->where('username', User::normalizeUsername($value))
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists();
                        if ($taken) {
                            $fail('Ese nombre de usuario ya lo tiene otra persona.');
                        }
                    })
                    ->dehydrateStateUsing(fn ($state) => User::normalizeUsername($state))
                    ->visible(fn (Get $get) => self::backoffice($get))
                    ->helperText('Opcional. Para entrar en el backoffice sin escribir el email (p. ej. «alex»).'),
                ColorPicker::make('color')->label('Color en el TPV')
                    ->helperText('Para reconocerlo de un vistazo en el plano de mesas.'),
                Toggle::make('is_active')->label('Activo')->default(true)->inline(false)
                    ->helperText('Un usuario inactivo no puede entrar ni usar su PIN.'),
            ]),
            Section::make('Acceso')->columns(2)->columnSpanFull()->schema([
                TextInput::make('new_pin')->label('Nuevo PIN')
                    ->password()->revealable()
                    ->regex('/^\d{4}$/')
                    ->validationMessages(['regex' => 'El PIN debe tener exactamente 4 dígitos.'])
                    ->maxLength(4)->inputMode('numeric')
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->helperText(fn (string $operation) => $operation === 'edit'
                        ? 'Déjalo vacío para mantener el PIN actual (no se puede ver).'
                        : '4 dígitos para entrar en el TPV.'),
                TextInput::make('password')->label(fn (string $operation) => $operation === 'edit' ? 'Nueva contraseña' : 'Contraseña')
                    ->password()->revealable()->minLength(8)
                    ->dehydrated(fn ($state) => filled($state))
                    ->visible(fn (Get $get) => self::backoffice($get))
                    ->required(fn (Get $get, string $operation) => $operation === 'create' && self::backoffice($get))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Déjala vacía para no cambiarla.' : 'Para entrar en el backoffice.'),
            ]),
        ]);
    }

    private static function backoffice(Get $get): bool
    {
        $role = $get('role');
        $role = $role instanceof UserRole ? $role : UserRole::tryFrom((string) $role);

        return (bool) $role?->canAccessBackoffice();
    }
}
