<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Código de configuración de un solo uso para crear el primer administrador
 * en /admin (la contraseña nunca pasa por la terminal). Lo genera install.sh
 * (tpv:setup-code) y lo guarda en /root/tpv-credenciales.txt; aquí solo se
 * guarda su hash. Al crear el primer administrador se borra.
 */
final class SetupCode
{
    /** Genera un código nuevo (solo si aún no hay usuarios) y devuelve el texto en claro. */
    public static function issue(): string
    {
        if (User::query()->exists()) {
            throw new \LogicException('Ya hay usuarios: el primer administrador ya está creado.');
        }
        // 12 caracteres sin ambigüedades (sin 0/O, 1/I/L), en grupos de 4: «K7QM-4XTR-9WHD».
        $code = implode('-', str_split(strtoupper(Str::of(Str::random(64))->replaceMatches('/[^A-HJKMNP-Z2-9]/i', '')->substr(0, 12)), 4));
        Setting::current()->update(['setup_code_hash' => Hash::make(self::normalize($code))]);

        return $code;
    }

    /** ¿Se puede usar la pantalla «Crear administrador»? */
    public static function available(): bool
    {
        return ! User::query()->exists()
            && Setting::query()->whereNotNull('setup_code_hash')->exists();
    }

    public static function check(string $code): bool
    {
        $hash = Setting::query()->value('setup_code_hash');

        return $hash !== null && Hash::check(self::normalize($code), $hash);
    }

    public static function invalidate(): void
    {
        Setting::query()->update(['setup_code_hash' => null]);
    }

    private static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
