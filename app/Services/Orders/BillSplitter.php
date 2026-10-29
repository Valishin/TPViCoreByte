<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Dividir la cuenta por productos: las unidades elegidas pasan a un pedido
 * nuevo (misma mesa y tipo) que se cobra con su propio ticket. Si se pasa
 * parte de una línea, se parte en dos (split_from_id guarda el origen).
 *
 * Dividir a partes iguales NO usa esto: es un único ticket con varios pagos.
 */
class BillSplitter
{
    public function __construct(private OrderTotalsCalculator $totals) {}

    /** @param  array<int, int>  $quantities  order_line_id (raíz) => unidades a separar */
    public function splitByProducts(Order $order, array $quantities, User $by): Order
    {
        return DB::transaction(function () use ($order, $quantities, $by) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->isOpen()) {
                throw new LogicException('El pedido no está abierto.');
            }

            $lines = $order->lines()->whereNull('parent_line_id')->whereNull('voided_at')->whereIn('id', array_keys($quantities))->with(['children.modifiers', 'modifiers'])->get()->keyBy('id');
            $quantities = array_filter($quantities);
            if ($quantities === [] || count($quantities) !== $lines->count()) {
                throw new InvalidArgumentException('Elige productos del pedido para separar.');
            }
            $remaining = $order->lines()->whereNull('parent_line_id')->whereNull('voided_at')->sum('quantity') - array_sum($quantities);
            if ($remaining <= 0) {
                throw new InvalidArgumentException('No puedes separar todo el pedido: cóbralo directamente.');
            }

            $new = $order->replicate(['subtotal', 'total', 'discount_amount', 'discount_type', 'discount_value', 'discount_reason', 'discounted_by', 'send_count']);
            $new->fill(['opened_by' => $by->id, 'opened_at' => now(), 'notes' => "Separado del pedido #{$order->id}"]);
            $new->save();

            foreach ($quantities as $lineId => $units) {
                $line = $lines[$lineId];
                if ($units > $line->quantity) {
                    throw new InvalidArgumentException("«{$line->name}» solo tiene {$line->quantity} unidades.");
                }
                if ($units === $line->quantity) {
                    // Línea entera: se mueve con sus hijas y modificadores.
                    OrderLine::query()->whereKey($line->id)->orWhere('parent_line_id', $line->id)->update(['order_id' => $new->id]);

                    continue;
                }

                // Parte de la línea: se queda una y se crea otra con las unidades separadas.
                $line->update(['quantity' => $line->quantity - $units]);
                $copy = $this->copy($line, $new, ['quantity' => $units, 'split_from_id' => $line->id]);
                foreach ($line->children as $child) {
                    $this->copy($child, $new, ['parent_line_id' => $copy->id]);
                }
            }

            $this->totals->apply($order->refresh());
            $this->totals->apply($new->refresh());

            return $new;
        });
    }

    private function copy(OrderLine $line, Order $order, array $overrides): OrderLine
    {
        $copy = $line->replicate(['line_total', 'order_discount_amount', 'taxable_amount', 'allocated_amount']);
        $copy->fill(['order_id' => $order->id] + $overrides);
        $copy->save();
        foreach ($line->modifiers as $modifier) {
            $copy->modifiers()->create($modifier->only(['modifier_id', 'name', 'price_delta', 'quantity']));
        }

        return $copy;
    }
}
