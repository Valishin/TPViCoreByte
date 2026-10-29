<?php

namespace App\Services\Cash;

use App\Enums\CashSessionStatus;
use App\Enums\OrderStatus;
use App\Exceptions\OpenOrdersException;
use App\Models\CashSession;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Apertura y cierre de caja (una por local). El número de Z es correlativo
 * (contador en settings con bloqueo) y el informe Z se congela al cerrar.
 */
class CashSessionService
{
    public function __construct(private CashReportService $reports) {}

    /** @param  array<string, int>|null  $breakdown  céntimos de la moneda/billete => unidades */
    public function open(User $by, int $openingFloat, ?array $breakdown = null): CashSession
    {
        if ($openingFloat < 0) {
            throw new InvalidArgumentException('El fondo inicial no puede ser negativo.');
        }
        if ($breakdown !== null && self::breakdownTotal($breakdown) !== $openingFloat) {
            throw new InvalidArgumentException('El desglose no suma el fondo inicial.');
        }

        return DB::transaction(function () use ($by, $openingFloat, $breakdown) {
            if (CashSession::query()->where('status', CashSessionStatus::Open)->lockForUpdate()->exists()) {
                throw new LogicException('Ya hay una caja abierta.');
            }

            return CashSession::create([
                'status' => CashSessionStatus::Open,
                'opened_by' => $by->id,
                'opened_at' => now(),
                'opening_float' => $openingFloat,
                'opening_breakdown' => $breakdown,
            ]);
        });
    }

    /**
     * Cierre Z. Con pedidos abiertos exige $force (confirmación explícita).
     *
     * @throws OpenOrdersException
     */
    public function close(CashSession $session, User $by, int $countedCash, ?string $notes = null, ?array $breakdown = null, bool $force = false): CashSession
    {
        if ($breakdown !== null && self::breakdownTotal($breakdown) !== $countedCash) {
            throw new InvalidArgumentException('El desglose no suma el efectivo contado.');
        }

        $this->assertNoOpenOrders($force);

        return DB::transaction(function () use ($session, $by, $countedCash, $notes, $breakdown) {
            $session = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== CashSessionStatus::Open) {
                throw new LogicException('La caja ya está cerrada.');
            }

            $settings = Setting::query()->lockForUpdate()->firstOrFail();
            $settings->increment('last_z_number');
            $session->z_number = $settings->last_z_number;

            $report = $this->reports->build($session, $countedCash, $notes);

            $session->update([
                'status' => CashSessionStatus::Closed,
                'z_number' => $settings->last_z_number,
                'closed_by' => $by->id,
                'closed_at' => now(),
                'expected_cash' => $report['cash']['expected'],
                'counted_cash' => $countedCash,
                'difference' => $report['cash']['difference'],
                'counted_breakdown' => $breakdown,
                'notes' => $notes,
                'z_report' => $report + ['closed_by' => $by->name],
            ]);

            return $session;
        });
    }

    /**
     * Con pedidos abiertos, el cierre necesita confirmación ($force). Se llama
     * también ANTES de pedir la autorización, para no gastar el PIN del
     * encargado en un intento que va a pedir confirmación.
     *
     * @throws OpenOrdersException
     */
    public function assertNoOpenOrders(bool $force): void
    {
        $open = Order::query()->where('status', OrderStatus::Open)->with('table')->get();
        if ($open->isNotEmpty() && ! $force) {
            throw new OpenOrdersException($open->map(fn (Order $o) => ($o->table ? "Mesa {$o->table->name}" : $o->type->label()).' · '.Format::money($o->total))->all());
        }
    }

    /** @param  array<string|int, int>  $breakdown  valor en céntimos => unidades */
    public static function breakdownTotal(array $breakdown): int
    {
        $total = 0;
        foreach ($breakdown as $value => $units) {
            $total += (int) $value * (int) $units;
        }

        return $total;
    }
}
