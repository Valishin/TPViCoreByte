<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

#[Fillable(['name', 'email', 'username', 'password', 'role', 'color', 'pin', 'is_active'])]
#[Hidden(['password', 'remember_token', 'pin'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Colores por defecto para distinguir camareros en el TPV. */
    public const COLORS = ['#f97316', '#0ea5e9', '#22c55e', '#a855f7', '#ef4444', '#eab308', '#14b8a6', '#ec4899'];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->color ??= self::COLORS[(int) User::query()->count() % count(self::COLORS)];
        });

        // Admin y encargado entran al backoffice con email: es obligatorio.
        static::saving(function (User $user) {
            if ($user->role?->canAccessBackoffice() && blank($user->email)) {
                throw ValidationException::withMessages([
                    'email' => 'El email es obligatorio para administradores y encargados.',
                ]);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /** Nombre de usuario para entrar en /admin: sin espacios y en minúsculas (vacío = null). */
    public static function normalizeUsername(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    protected function username(): Attribute
    {
        return Attribute::set(fn (?string $value) => self::normalizeUsername($value));
    }

    /** PIN de 4 dígitos para el TPV; se guarda hasheado. */
    protected function pin(): Attribute
    {
        return Attribute::set(function (?string $value) {
            if ($value === null) {
                return null;
            }
            if (! preg_match('/^\d{4}$/', $value)) {
                throw new InvalidArgumentException('El PIN debe tener 4 dígitos.');
            }

            return Hash::make($value);
        });
    }

    /** Acceso al backoffice /admin por email (o usuario) y contraseña: admin y encargado activos. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role->canAccessBackoffice();
    }

    public function canUsePos(): bool
    {
        return $this->is_active && $this->pin !== null && $this->role->canUsePos();
    }

    /** Repartidor activo con PIN: entra en el móvil y solo ve sus repartos. */
    public function canUseCourierApp(): bool
    {
        return $this->is_active && $this->pin !== null && $this->role === UserRole::Courier;
    }

    /** Puede entrar con PIN (personal de sala o repartidor). */
    public function canSignInPos(): bool
    {
        return $this->canUsePos() || $this->canUseCourierApp();
    }

    /** "Ana García" → "AG"; "Luis" → "LU". */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/\(.*?\)/', '', $this->name)));

        return mb_strtoupper(count($words) > 1
            ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1)
            : mb_substr($words[0], 0, 2));
    }

    public function checkPin(string $pin): bool
    {
        return $this->is_active && $this->pin !== null && Hash::check($pin, $this->pin);
    }
}
