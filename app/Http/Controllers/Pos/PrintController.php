<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PrintJobKind;
use App\Enums\PrintJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\OrderResource;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Pos\PrintJobDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Estado de impresoras y envíos fallidos (avisos persistentes en todas las pantallas). */
class PrintController extends Controller
{
    public function printers(): JsonResponse
    {
        return response()->json(['data' => Printer::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Printer $p) => [
                'id' => $p->id, 'name' => $p->name, 'type' => $p->type->value,
                'status' => $p->status->value, 'error' => $p->last_error,
            ])]);
    }

    public function failed(): JsonResponse
    {
        // Las páginas de prueba fallidas no generan aviso persistente (se ven en /admin al momento).
        $jobs = PrintJob::query()->where('status', PrintJobStatus::Failed)->where('kind', '!=', PrintJobKind::Test)->with(['printer', 'order.table'])->latest('id')->limit(50)->get();

        return response()->json(['data' => $jobs->map(fn (PrintJob $job) => OrderResource::printJob($job))]);
    }

    public function retry(PrintJob $printJob, PrintJobDispatcher $printing): JsonResponse
    {
        return response()->json(['data' => OrderResource::printJob($printing->retry($printJob))]);
    }

    public function redirect(Request $request, PrintJob $printJob, PrintJobDispatcher $printing): JsonResponse
    {
        $data = $request->validate(['printer_id' => ['required', 'integer', 'exists:printers,id']]);

        return response()->json(['data' => OrderResource::printJob($printing->redirect($printJob, Printer::query()->findOrFail($data['printer_id'])))]);
    }
}
