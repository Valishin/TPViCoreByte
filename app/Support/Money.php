<?php

namespace App\Support;

/**
 * Aritmética de importes en céntimos, solo con enteros (nunca floats).
 * Porcentajes e IVA en puntos básicos: 1000 = 10 %.
 */
final class Money
{
    /** Porcentaje de un importe, redondeando el medio céntimo hacia arriba. */
    public static function percentage(int $amount, int $basisPoints): int
    {
        return self::divRound($amount * $basisPoints, 10000);
    }

    /** Mitad de un importe, redondeando el medio céntimo hacia arriba. */
    public static function half(int $amount): int
    {
        return self::divRound($amount, 2);
    }

    /** Media de varios importes, redondeando el medio céntimo hacia arriba. */
    public static function average(array $amounts): int
    {
        return $amounts === [] ? 0 : self::divRound(array_sum($amounts), count($amounts));
    }

    /** Base imponible de un importe con IVA incluido. La cuota es total - base. */
    public static function vatBase(int $gross, int $rateBasisPoints): int
    {
        return self::divRound($gross * 10000, 10000 + $rateBasisPoints);
    }

    /**
     * Reparte un importe en proporción a unos pesos, cuadrando al céntimo:
     * cada parte se trunca y el resto va a la parte de mayor peso (la de
     * mayor importe). Conserva las claves. Si todos los pesos son 0, reparte
     * a partes iguales.
     *
     * @param  array<array-key, int>  $weights
     * @return array<array-key, int>
     */
    public static function allocate(int $total, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        if (array_sum($weights) <= 0) {
            $weights = array_fill_keys(array_keys($weights), 1);
        }

        $sum = array_sum($weights);
        $shares = [];
        foreach ($weights as $key => $weight) {
            $shares[$key] = intdiv($total * $weight, $sum);
        }

        $largest = array_search(max($weights), $weights, true);
        $shares[$largest] += $total - array_sum($shares);

        return $shares;
    }

    /** División entera redondeando a la mitad hacia fuera del cero (half up simétrico). */
    private static function divRound(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;

        return $sign * intdiv(2 * abs($numerator) + abs($denominator), 2 * abs($denominator));
    }
}
