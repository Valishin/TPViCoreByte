<?php

namespace App\Services\Pos;

use App\Enums\AuthorizationAction;
use App\Enums\UserRole;
use App\Exceptions\AuthorizationRequiredException;
use App\Models\Authorization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Autorización de encargado sin cerrar la sesión del camarero.
 *
 * 1. El encargado teclea su PIN (grant) → token de un solo uso, ligado a la
 *    acción y al camarero, que caduca a los 2 minutos.
 * 2. La acción llama a require(): si el usuario es encargado/admin se
 *    autoriza él mismo; si no, consume el token. Siempre queda registrado
 *    en `authorizations`.
 */
class AuthorizationService
{
    public const TTL_SECONDS = 120;

    public static function canAuthorize(User $user): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function grant(User $requester, int $managerId, string $pin, AuthorizationAction $action): string
    {
        $key = 'pos-authorize:'.$managerId;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['pin' => 'Demasiados intentos. Espera '.RateLimiter::availableIn($key).' s.']);
        }

        $manager = User::query()->find($managerId);
        if (! $manager || ! self::canAuthorize($manager) || ! $manager->checkPin($pin)) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['pin' => 'PIN incorrecto o usuario sin permiso para autorizar.']);
        }
        RateLimiter::clear($key);

        $token = Str::random(40);
        Cache::put("pos-authorization:{$token}", [
            'action' => $action->value, 'manager_id' => $manager->id, 'requester_id' => $requester->id,
        ], self::TTL_SECONDS);

        return $token;
    }

    /**
     * Exige autorización para $action. Devuelve quién autorizó (el propio
     * usuario si es encargado/admin) y lo registra.
     *
     * @throws AuthorizationRequiredException
     */
    public function require(AuthorizationAction $action, User $user, ?string $token, ?Model $subject = null, ?string $reason = null, array $details = []): User
    {
        if (self::canAuthorize($user)) {
            $manager = $user;
        } else {
            $data = $token ? Cache::pull("pos-authorization:{$token}") : null;
            if (! $data || $data['action'] !== $action->value || $data['requester_id'] !== $user->id) {
                throw new AuthorizationRequiredException($action);
            }
            $manager = User::query()->findOrFail($data['manager_id']);
        }

        Authorization::create([
            'action' => $action,
            'requested_by' => $user->id,
            'authorized_by' => $manager->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'reason' => $reason,
            'details' => $details ?: null,
        ]);

        return $manager;
    }
}
