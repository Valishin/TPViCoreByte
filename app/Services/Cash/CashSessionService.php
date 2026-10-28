<?php

namespace App\Services\Cash;

use App\Enums\CashSessionStatus;
use App\Enums\PaymentMethod;
use App\Models\CashSession;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Apertura y cierre de caja. El número de Z es correlativo (contador en settings con bloqueo). */
class CashSessionService
{
    public function open(User $by, int $openingFloat): CashSession
    {
        if (CashSession::query()->where('status', CashSessionStatus::Open)->exists()) {
            throw new LogicException('Ya hay una caja abierta.');
        }

        return CashSession::create([
            'status' => CashSessionStatus::Open,
            'opened_by' => $by->id,
            'opened_at' => now(),
            'opening_float' => $openingFloat,
        ]);
    }

    public function close(CashSession $session, User $by, int $countedCash, ?string $notes = null): CashSession
    {
        return DB::transaction(function () use ($session, $by, $countedCash, $notes) {
            $session = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== CashSessionStatus::Open) {
                throw new LogicException('La caja ya está cerrada.');
            }

            $settings = Setting::query()->lockForUpdate()->firstOrFail();
            $settings->increment('last_z_number');

            $cashIn = (int) $session->payments()->where('method', PaymentMethod::Cash)->sum('amount');
            $expected = $session->opening_float + $cashIn;

            $session->update([
                'status' => CashSessionStatus::Closed,
                'z_number' => $settings->last_z_number,
                'closed_by' => $by->id,
                'closed_at' => now(),
                'expected_cash' => $expected,
                'counted_cash' => $countedCash,
                'difference' => $countedCash - $expected,
                'notes' => $notes,
            ]);

            return $session;
        });
    }
}
