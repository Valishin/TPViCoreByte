<?php

namespace App\Http\Controllers\Pos;

use App\Enums\AuthorizationAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\StaffResource;
use App\Models\Setting;
use App\Models\User;
use App\Services\Pos\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
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
            ->filter->canSignInPos()->values();

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
        if (! $user->canSignInPos()) {
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
                'discount_limit_bp' => $settings->discount_limit_bp,
                'print_ticket_on_checkout' => $settings->print_ticket_on_checkout,
                'show_onscreen_keyboard_pos' => $settings->show_onscreen_keyboard_pos,
                'invoice_require_address' => $settings->invoice_require_address,
                // Avisos de tiempo (/admin → Ajustes del local → Avisos de tiempo).
                'time_alerts' => [
                    'enabled' => $settings->time_alerts_enabled,
                    'takeaway_minutes' => $settings->time_alert_takeaway_minutes,
                    'table_minutes' => $settings->time_alert_table_minutes,
                ],
                // Por encima de estos importes la pantalla pide confirmación.
                'amount_limits' => [
                    'opening_float' => $settings->max_opening_float,
                    'cash_count' => $settings->max_cash_count,
                    'cash_tendered' => $settings->max_cash_tendered,
                    'tip' => $settings->max_tip,
                ],
            ],
            'permissions' => [
                'authorize' => AuthorizationService::canAuthorize($request->user()),
            ],
        ]);
    }

    /** Encargados que pueden autorizar (para el modal "Autorizar"). */
    public function managers(): JsonResponse
    {
        $managers = User::query()->where('is_active', true)->whereNotNull('pin')->orderBy('name')->get()
            ->filter(fn (User $u) => AuthorizationService::canAuthorize($u))->values();

        return response()->json(['data' => StaffResource::collection($managers)]);
    }

    /** El encargado teclea su PIN → token de un solo uso para la acción. */
    public function grantAuthorization(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $data = $request->validate([
            'manager_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
            'action' => ['required', Rule::enum(AuthorizationAction::class)],
        ]);

        $token = $authorization->grant($request->user(), $data['manager_id'], $data['pin'], AuthorizationAction::from($data['action']));

        return response()->json(['token' => $token, 'expires_in' => AuthorizationService::TTL_SECONDS]);
    }

    /** Cierra solo la sesión del TPV (no la del backoffice si la hubiera). */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('pos')->logout();
        $request->session()->regenerateToken();

        return response()->json(['status' => 'ok']);
    }
}
