<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use SensitiveParameter;

/** Entrada a /admin con email o nombre de usuario. */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email o usuario')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    /** Con «@» es un email; si no, el nombre de usuario (en minúsculas, como se guarda). */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = trim((string) $data['email']);

        return [
            ...(str_contains($login, '@') ? ['email' => $login] : ['username' => User::normalizeUsername($login)]),
            'password' => $data['password'],
        ];
    }
}
