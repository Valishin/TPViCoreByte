<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\StaffResource;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Entrada al TPV con PIN (guard `pos`, sesión por cookie). */
class AuthController extends Controller
{
    public const MAX_PER_USER = 5;

    public const MAX_PER_IP = 30;

    public const DECAY_SECONDS = 300;

    public function staff(): JsonResponse
    {
        $staff = User::query()->where('is_active', true)->whereNotNull('pin')->orderBy('name')->get()
            ->filter->canUsePos()->values();

        return response()->json(['data' => StaffResource::collection($staff)]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
        ], ['pin.regex' => 'El PIN tiene 4 dígitos.']);

        $userKey = 'pos-pin:user:'.$data['user_id'];
        $ipKey = 'pos-pin:ip:'.$request->ip();
        foreach ([$userKey => self::MAX_PER_USER, $ipKey => self::MAX_PER_IP] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return response()->json([
                    'message' => 'Demasiados intentos. Espera '.RateLimiter::availableIn($key).' s.',
                    'retry_after' => RateLimiter::availableIn($key),
                ], 429);
            }
        }

        $user = User::query()->find($data['user_id']);
        if (! $user?->checkPin($data['pin'])) {
            RateLimiter::hit($userKey, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['pin' => 'PIN incorrecto.']);
        }
        if (! $user->canUsePos()) {
            return response()->json(['message' => 'Este usuario no puede usar el TPV de sala.'], 403);
        }

        RateLimiter::clear($userKey);
        Auth::guard('pos')->login($user);
        $request->session()->regenerate();

        return $this->me($request->setUserResolver(fn () => $user));
    }

    public function me(Request $request): JsonResponse
    {
        $settings = Setting::current();

        return response()->json([
            'user' => new StaffResource($request->user()),
            'settings' => [
                'trade_name' => $settings->trade_name,
                'enabled_order_types' => $settings->enabled_order_types->map->value->values(),
                'auto_lock_minutes_pos' => $settings->auto_lock_minutes_pos,
                'auto_lock_minutes_mobile' => $settings->auto_lock_minutes_mobile,
                'lock_pos_after_send' => $settings->lock_pos_after_send,
            ],
        ]);
    }

    /** Cierra solo la sesión del TPV (no la del backoffice si la hubiera). */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('pos')->logout();
        $request->session()->regenerateToken();

        return response()->json(['status' => 'ok']);
    }
}
