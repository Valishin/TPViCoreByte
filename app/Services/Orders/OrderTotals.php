<?php

namespace App\Services\Orders;

/** Resultado de OrderTotalsCalculator. Importes en céntimos, IVA incluido. */
final readonly class OrderTotals
{
    /**
     * @param  array<int, array<string, int>>  $lines  campos calculados por línea (line_total, discount_amount, order_discount_amount, taxable_amount, allocated_amount, unit_price)
     * @param  list<array{rate: int, base: int, tax: int, total: int}>  $vatBreakdown
     */
    public function __construct(
        public array $lines,
        public int $linesGross,       // líneas antes de descuentos
        public int $linesDiscount,    // descuentos e invitaciones de línea
        public int $subtotal,         // líneas tras sus descuentos
        public int $orderDiscount,    // descuento global
        public int $deliveryFee,
        public int $total,
        public array $vatBreakdown,
    ) {}

    public function taxBase(): int
    {
        return array_sum(array_column($this->vatBreakdown, 'base'));
    }

    public function taxAmount(): int
    {
        return array_sum(array_column($this->vatBreakdown, 'tax'));
    }

    /** Total de un tipo de IVA concreto (0 si no hay). */
    public function totalForRate(int $rate): int
    {
        foreach ($this->vatBreakdown as $row) {
            if ($row['rate'] === $rate) {
                return $row['total'];
            }
        }

        return 0;
    }
}
