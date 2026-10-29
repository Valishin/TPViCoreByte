<?php

namespace App\Services\Pos;

use App\Enums\PrintJobKind;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Printer;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\TakeawayOrderService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * "Enviar" una comanda: agrupa las líneas pendientes por impresora (cada
 * componente de menú y cada mitad va a la suya), lanza un envío por
 * impresora y marca las líneas como enviadas con su número de envío.
 */
class OrderSender
{
    public function __construct(
        private KitchenFormatter $formatter,
        private PrintJobDispatcher $printing,
        private OrderService $orders,
        private TakeawayOrderService $takeaway,
    ) {}

    /**
     * @return array{send_number: int|null, jobs: list<array{print_job_id: int, printer: string, lines: int}>, unrouted: list<string>}
     */
    public function send(Order $order, User $by): array
    {
        return DB::transaction(function () use ($order, $by) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->isOpen()) {
                throw new LogicException('El pedido no está abierto.');
            }

            /** @var Collection<int, OrderLine> $pending */
            $pending = $order->lines()->with(['modifiers', 'product', 'parent.children'])
                ->whereNull('sent_at')->whereNull('voided_at')->get();

            if ($pending->isEmpty()) {
                return ['send_number' => null, 'jobs' => [], 'unrouted' => []];
            }

            $sendNumber = $order->send_count + 1;

            // Qué se imprime: líneas sin hijas y las hijas (no el padre de un menú/mitad).
            $printable = $pending->reject(fn (OrderLine $line) => $pending->contains('parent_line_id', $line->id));
            $printers = Printer::query()->whereIn('id', $printable->pluck('printer_id')->filter()->unique())->get()->keyBy('id');

            $jobs = [];
            $unrouted = [];
            foreach ($printable->groupBy(fn (OrderLine $line) => $line->printer_id ?? 0) as $printerId => $lines) {
                $printer = $printers->get($printerId);
                if (! $printer || ! $printer->is_active) {
                    array_push($unrouted, ...$lines->pluck('name')->all());

                    continue;
                }

                $job = $this->printing->dispatch(
                    $order, $printer, PrintJobKind::Kitchen,
                    $this->formatter->kitchen($order, $lines->values(), $sendNumber, $by),
                    $lines->pluck('id')->all(), $sendNumber, $by,
                );
                $jobs[] = ['print_job_id' => $job->id, 'printer' => $printer->name, 'lines' => $lines->count()];
            }

            OrderLine::query()->whereIn('id', $pending->pluck('id'))->update(['sent_at' => now(), 'send_number' => $sendNumber]);
            $order->update(['send_count' => $sendNumber]);

            // Si habían pedido la cuenta y piden más, la mesa vuelve a "ocupada".
            $this->orders->reopenAfterSend($order);
            // Llevar / domicilio: Pendiente → En cocina.
            $this->takeaway->sentToKitchen($order);

            return ['send_number' => $sendNumber, 'jobs' => $jobs, 'unrouted' => array_values(array_unique($unrouted))];
        });
    }
}
