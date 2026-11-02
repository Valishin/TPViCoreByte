<?php

namespace App\Http\Resources\Pos;

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PrintJob;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderTotalsCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pedido para la pantalla de comanda: líneas en árbol, envíos con su estado
 * de impresión y totales (céntimos).
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['lines.modifiers', 'table.room', 'openedBy', 'printJobs.printer']);
        $lines = $this->lines;
        $totals = app(OrderTotalsCalculator::class)->calculate($this->resource);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'table' => $this->table ? [
                'id' => $this->table->id, 'name' => $this->table->name,
                'room' => $this->table->room?->name, 'status' => $this->table->status->value,
            ] : null,
            // Mesas unidas: «Mesa 3 + 4», la principal primero.
            'table_label' => $this->resource->tableLabel(),
            'tables' => $this->table_id ? $this->resource->activeTableLinks()->with('diningTable')->get()->map(fn ($link) => [
                'id' => $link->table_id, 'name' => $link->diningTable->name, 'is_main' => $link->is_main,
            ])->values() : [],
            // Otras cuentas abiertas en la mesa principal (división por productos).
            'other_accounts' => $this->table_id ? Order::query()->where('table_id', $this->table_id)->where('status', 'abierto')
                ->whereKeyNot($this->id)->orderBy('id')->get(['id', 'total'])->map(fn ($o) => ['id' => $o->id, 'total' => $o->total])->values() : [],
            'merged_into_order_id' => $this->merged_into_order_id,
            'precheck_printed' => $this->resource->prechecks()->exists(),
            'release_blocker' => ($this->table_id || $this->type === OrderType::Bar) && $this->resource->isOpen() ? app(OrderService::class)->releaseBlocker($this->resource) : null,
            'guests' => $this->guests,
            'notes' => $this->notes,
            'opened_at' => $this->opened_at->toIso8601String(),
            'opened_by' => $this->openedBy ? ['id' => $this->openedBy->id, 'name' => $this->openedBy->name] : null,
            'lines_gross' => $totals->linesGross,
            'lines_discount' => $totals->linesDiscount,
            'subtotal' => $this->subtotal,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value,
            'delivery_fee' => $totals->deliveryFee,
            'vat_breakdown' => $totals->vatBreakdown,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'send_count' => $this->send_count,
            'pending_count' => $lines->whereNull('parent_line_id')->whereNull('sent_at')->whereNull('voided_at')->count(),
            'lines' => $lines->whereNull('parent_line_id')->values()->map(fn (OrderLine $line) => $this->line($line, $lines)),
            'sends' => $this->sends($lines),
            'print_jobs' => $this->printJobs->map(fn (PrintJob $job) => self::printJob($job))->values(),
            'takeaway' => $this->resource->isTakeawayOrDelivery() ? (new TakeawayOrderResource($this->resource))->resolve($request) : null,
        ];
    }

    private function line(OrderLine $line, $all): array
    {
        return [
            'id' => $line->id,
            'kind' => $line->kind->value,
            'product_id' => $line->product_id,
            'name' => $line->name,
            'variant_name' => $line->variant_name,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'line_total' => $line->line_total,
            'discount_amount' => $line->discount_amount,
            'is_invitation' => $line->is_invitation,
            'notes' => $line->notes,
            'modifiers' => $line->modifiers->map(fn ($m) => ['name' => $m->name, 'price_delta' => $m->price_delta, 'quantity' => $m->quantity])->values(),
            'sent_at' => $line->sent_at?->toIso8601String(),
            'send_number' => $line->send_number,
            'voided_at' => $line->voided_at?->toIso8601String(),
            'void_reason' => $line->void_reason,
            'children' => $all->where('parent_line_id', $line->id)->values()->map(fn (OrderLine $child) => [
                'id' => $child->id,
                'kind' => $child->kind->value,
                'name' => $child->name,
                'variant_name' => $child->variant_name,
                'quantity' => $child->quantity,
                'unit_price' => $child->unit_price,
                'notes' => $child->notes,
                'modifiers' => $child->modifiers->map(fn ($m) => ['name' => $m->name, 'price_delta' => $m->price_delta, 'quantity' => $m->quantity])->values(),
            ]),
        ];
    }

    /** Envíos ("1.er envío 21:14") a partir de las líneas. */
    private function sends($lines): array
    {
        return $lines->whereNotNull('send_number')->groupBy('send_number')->sortKeys()
            ->map(fn ($group, $number) => ['number' => (int) $number, 'sent_at' => $group->min('sent_at')?->toIso8601String()])
            ->values()->all();
    }

    public static function printJob(PrintJob $job): array
    {
        $job->loadMissing(['printer', 'order.table']);

        return [
            'id' => $job->id,
            'order_id' => $job->order_id,
            'table' => $job->order?->table?->name,
            'order_type' => $job->order?->type->value,
            'kind' => $job->kind->value,
            'send_number' => $job->send_number,
            'printer' => $job->printer ? ['id' => $job->printer->id, 'name' => $job->printer->name] : null,
            'status' => $job->status->value,
            'error' => $job->error,
            'attempts' => $job->attempts,
            'created_at' => $job->created_at->toIso8601String(),
        ];
    }
}
