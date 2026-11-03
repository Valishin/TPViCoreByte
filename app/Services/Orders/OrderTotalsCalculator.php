<?php

namespace App\Services\Orders;

use App\Enums\DiscountType;
use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderLineKind;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcula los totales de un pedido y su desglose de IVA.
 *
 * Reglas (todos los importes en céntimos con IVA incluido):
 *  1. Línea simple: (precio + modificadores) × cantidad.
 *  2. Línea compuesta (menú o mitad y mitad): el precio del padre se reparte
 *     entre las hijas en proporción a su precio suelto (reference_price ×
 *     cantidad); el resto de redondeo va a la hija de mayor importe. Cada
 *     parte tributa al IVA de su hija, igual que su suplemento y sus
 *     modificadores. En mitad y mitad el precio del padre sale de la regla
 *     configurada (la más cara o la media) y los extras de cada mitad se
 *     cobran enteros o a mitad según settings.
 *  3. Descuento / invitación de línea: se reparte entre las partes de la
 *     línea en proporción a su importe.
 *  4. Descuento global: sobre el subtotal ya descontado, repartido entre todas
 *     las partes en proporción a su importe. No afecta a los gastos de envío.
 *  5. IVA: se agrupa por tipo y se calcula la base sobre el total del grupo
 *     (no línea a línea) para que el redondeo no descuadre.
 *
 * Las líneas anuladas no cuentan.
 */
class OrderTotalsCalculator
{
    public function calculate(Order $order, ?Setting $settings = null): OrderTotals
    {
        $settings ??= Setting::current();
        $order->loadMissing('lines.modifiers');

        /** @var Collection<int, OrderLine> $lines */
        $lines = $order->lines->reject(fn (OrderLine $line) => $line->isVoided());
        $children = $lines->whereNotNull('parent_line_id')->groupBy('parent_line_id');
        $roots = $lines->whereNull('parent_line_id');

        $results = [];  // line_id => campos calculados
        $parts = [];    // partes que tributan: [line_id, root_id, rate, amount, net, taxable]
        $linesGross = 0;
        $linesDiscount = 0;

        foreach ($roots as $root) {
            $rootParts = $this->rootParts($root, $children->get($root->id, collect()), $settings, $results);
            $gross = array_sum(array_column($rootParts, 'amount'));

            $discount = $this->lineDiscount($root, $gross);
            $shares = Money::allocate($discount, array_column($rootParts, 'amount'));
            foreach ($rootParts as $i => $part) {
                $rootParts[$i]['net'] = $part['amount'] - $shares[$i];
            }

            $results[$root->id] = ($results[$root->id] ?? []) + [
                'line_total' => $gross,
                'discount_amount' => $discount,
            ];

            $linesGross += $gross;
            $linesDiscount += $discount;
            array_push($parts, ...$rootParts);
        }

        $subtotal = $linesGross - $linesDiscount;
        $orderDiscount = $this->orderDiscount($order, $subtotal);
        $shares = $parts === [] ? [] : Money::allocate($orderDiscount, array_column($parts, 'net'));

        foreach ($parts as $i => $part) {
            $parts[$i]['taxable'] = $part['net'] - $shares[$i];
        }

        // Agregados por línea.
        foreach ($lines as $line) {
            $results[$line->id] = ($results[$line->id] ?? []) + ['line_total' => 0, 'discount_amount' => 0];
            $results[$line->id]['taxable_amount'] = 0;
            $results[$line->id]['order_discount_amount'] = 0;
        }
        foreach ($parts as $i => $part) {
            $results[$part['line_id']]['taxable_amount'] += $part['taxable'];
            $results[$part['root_id']]['order_discount_amount'] += $shares[$i];
        }

        // Gastos de envío (solo domicilio, sin descuentos).
        $deliveryFee = $order->type === OrderType::Delivery ? (int) $order->delivery_fee : 0;
        $byRate = [];
        foreach ($parts as $part) {
            $byRate[$part['rate']] = ($byRate[$part['rate']] ?? 0) + $part['taxable'];
        }
        if ($deliveryFee > 0) {
            $rate = $settings->delivery_fee_vat_rate;
            $byRate[$rate] = ($byRate[$rate] ?? 0) + $deliveryFee;
        }
        ksort($byRate);

        $breakdown = [];
        foreach ($byRate as $rate => $total) {
            if ($total === 0) {
                continue;
            }
            $base = Money::vatBase($total, $rate);
            $breakdown[] = ['rate' => $rate, 'base' => $base, 'tax' => $total - $base, 'total' => $total];
        }

        return new OrderTotals(
            lines: $results,
            linesGross: $linesGross,
            linesDiscount: $linesDiscount,
            subtotal: $subtotal,
            orderDiscount: $orderDiscount,
            deliveryFee: $deliveryFee,
            total: $subtotal - $orderDiscount + $deliveryFee,
            vatBreakdown: $breakdown,
        );
    }

    /** Calcula y guarda los campos calculados en las líneas y la caché del pedido. */
    public function apply(Order $order): OrderTotals
    {
        $totals = $this->calculate($order);

        DB::transaction(function () use ($order, $totals) {
            foreach ($order->lines as $line) {
                $values = $totals->lines[$line->id] ?? [
                    'line_total' => 0, 'discount_amount' => 0, 'order_discount_amount' => 0, 'taxable_amount' => 0,
                ];
                $line->forceFill($values)->saveQuietly();
            }

            $order->forceFill([
                'subtotal' => $totals->subtotal,
                'discount_amount' => $totals->orderDiscount,
                'total' => $totals->total,
            ])->saveQuietly();
        });

        return $totals;
    }

    /**
     * Partes que tributan de una línea raíz (ella misma o sus hijas).
     *
     * @param  Collection<int, OrderLine>  $children
     * @return list<array{line_id: int, root_id: int, rate: int, amount: int}>
     */
    private function rootParts(OrderLine $root, Collection $children, Setting $settings, array &$results): array
    {
        $qty = $root->quantity;
        $ownModifiers = $this->modifiersTotal($root, false, $settings);

        if ($children->isEmpty()) {
            return [$this->part($root, $root, ($root->unit_price + $ownModifiers) * $qty)];
        }

        $isHalf = $children->first()->kind === OrderLineKind::Half;
        $parentUnit = $isHalf
            ? $this->halfAndHalfPrice($children, $settings) + (int) $root->half_and_half_surcharge
            : $root->unit_price;
        if ($isHalf) {
            $results[$root->id]['unit_price'] = $parentUnit;
        }

        $weights = $children->mapWithKeys(fn (OrderLine $c) => [$c->id => ($c->reference_price ?? 0) * $c->quantity])->all();
        $allocation = Money::allocate($parentUnit, $weights);

        $parts = [];
        foreach ($children as $child) {
            $childUnit = $allocation[$child->id]
                + ($child->unit_price + $this->modifiersTotal($child, $isHalf, $settings)) * $child->quantity;
            $amount = $childUnit * $qty;

            $results[$child->id] = ['allocated_amount' => $allocation[$child->id], 'line_total' => $amount, 'discount_amount' => 0];
            $parts[] = $this->part($child, $root, $amount);
        }

        if ($ownModifiers !== 0) {
            $parts[] = $this->part($root, $root, $ownModifiers * $qty);
        }

        return $parts;
    }

    private function part(OrderLine $line, OrderLine $root, int $amount): array
    {
        return ['line_id' => $line->id, 'root_id' => $root->id, 'rate' => $line->vat_rate, 'amount' => $amount];
    }

    /** @param  Collection<int, OrderLine>  $halves */
    private function halfAndHalfPrice(Collection $halves, Setting $settings): int
    {
        $prices = $halves->map(fn (OrderLine $half) => (int) $half->reference_price)->all();

        return match ($settings->half_and_half_pricing) {
            HalfAndHalfPricing::MostExpensive => max($prices),
            HalfAndHalfPricing::Average => Money::average($prices),
        };
    }

    private function modifiersTotal(OrderLine $line, bool $isHalf, Setting $settings): int
    {
        $halfPrice = $isHalf && $settings->half_and_half_modifier_pricing === HalfAndHalfModifierPricing::Half;

        return $line->modifiers->sum(function ($modifier) use ($halfPrice) {
            $delta = $halfPrice ? Money::half($modifier->price_delta) : $modifier->price_delta;

            return $delta * $modifier->quantity;
        });
    }

    private function lineDiscount(OrderLine $line, int $gross): int
    {
        if ($line->is_invitation) {
            return $gross;
        }

        return $this->discount($line->discount_type, $line->discount_value, $gross);
    }

    private function orderDiscount(Order $order, int $subtotal): int
    {
        return $this->discount($order->discount_type, $order->discount_value, $subtotal);
    }

    private function discount(?DiscountType $type, ?int $value, int $base): int
    {
        if ($type === null || ! $value || $base <= 0) {
            return 0;
        }

        $amount = match ($type) {
            DiscountType::Percentage => Money::percentage($base, $value),
            DiscountType::Amount => $value,
        };

        return min($amount, $base);
    }
}
