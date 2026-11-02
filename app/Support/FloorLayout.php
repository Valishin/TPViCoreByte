<?php

namespace App\Support;

use App\Models\Table;

/**
 * Posición de las mesas en el plano de la caja (cuadrícula: una mesa de
 * tamaño w×h ocupa 2w×2h celdas desde pos_x/pos_y). Las mesas creadas en
 * /admin no tenían posición (0,0): se dibujaban unas encima de otras y
 * «desaparecían» del plano con sus pedidos abiertos. Huecos cada 3 celdas,
 * 4 por fila, como los datos de demo.
 */
final class FloorLayout
{
    public const STEP = 3;

    public const PER_ROW = 4;

    /** Primer hueco libre de la sala para una mesa w×h (sin contar $ignoreId). @return array{0: int, 1: int} */
    public static function freeSlot(int $roomId, int $w = 1, int $h = 1, ?int $ignoreId = null): array
    {
        $taken = Table::query()->where('room_id', $roomId)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get(['pos_x', 'pos_y', 'width', 'height'])->all();

        for ($i = 0; ; $i++) {
            $x = ($i % self::PER_ROW) * self::STEP;
            $y = intdiv($i, self::PER_ROW) * self::STEP;
            $free = true;
            foreach ($taken as $t) {
                if (self::overlaps($x, $y, $w, $h, $t->pos_x, $t->pos_y, $t->width, $t->height)) {
                    $free = false;
                    break;
                }
            }
            if ($free) {
                return [$x, $y];
            }
        }
    }

    public static function overlaps(int $x1, int $y1, int $w1, int $h1, int $x2, int $y2, int $w2, int $h2): bool
    {
        return $x1 < $x2 + $w2 * 2 && $x2 < $x1 + $w1 * 2 && $y1 < $y2 + $h2 * 2 && $y2 < $y1 + $h1 * 2;
    }

    /** ¿Se pisa con otra mesa de la sala? */
    public static function collides(Table $table): bool
    {
        return Table::query()->where('room_id', $table->room_id)->when($table->exists, fn ($q) => $q->whereKeyNot($table->id))
            ->get(['pos_x', 'pos_y', 'width', 'height'])
            ->contains(fn ($t) => self::overlaps((int) $table->pos_x, (int) $table->pos_y, (int) ($table->width ?: 1), (int) ($table->height ?: 1),
                $t->pos_x, $t->pos_y, $t->width, $t->height));
    }

    /** Separa las mesas que se pisan (las más antiguas se quedan donde están). @return int mesas movidas */
    public static function untangle(): int
    {
        $moved = 0;
        foreach (Table::query()->orderBy('room_id')->orderBy('id')->get() as $table) {
            $placed = Table::query()->where('room_id', $table->room_id)->where('id', '<', $table->id)->get(['pos_x', 'pos_y', 'width', 'height']);
            $clash = $placed->contains(fn ($t) => self::overlaps($table->pos_x, $table->pos_y, $table->width, $table->height, $t->pos_x, $t->pos_y, $t->width, $t->height));
            if ($clash) {
                [$x, $y] = self::freeSlotAmong($placed->all(), $table->width, $table->height);
                $table->forceFill(['pos_x' => $x, 'pos_y' => $y])->saveQuietly();
                $moved++;
            }
        }

        return $moved;
    }

    private static function freeSlotAmong(array $taken, int $w, int $h): array
    {
        for ($i = 0; ; $i++) {
            $x = ($i % self::PER_ROW) * self::STEP;
            $y = intdiv($i, self::PER_ROW) * self::STEP;
            if (! collect($taken)->contains(fn ($t) => self::overlaps($x, $y, $w, $h, $t->pos_x, $t->pos_y, $t->width, $t->height))) {
                return [$x, $y];
            }
        }
    }
}
