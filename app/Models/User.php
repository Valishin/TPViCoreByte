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
use InvalidArgumentException;

#[Fillable(['name', 'email', 'password', 'role', 'pin', 'is_active'])]
#[Hidden(['password', 'remember_token', 'pin'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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

    /** Acceso al backoffice /admin por email y contraseña: admin y encargado activos. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role->canAccessBackoffice();
    }

    public function checkPin(string $pin): bool
    {
        return $this->is_active && $this->pin !== null && Hash::check($pin, $this->pin);
    }
}
