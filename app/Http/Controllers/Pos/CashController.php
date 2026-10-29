<?php

namespace App\Http\Controllers\Pos;

use App\Enums\AuthorizationAction;
use App\Enums\PrintJobKind;
use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Ticket;
use App\Services\Cash\CashReportService;
use App\Services\Cash\CashSessionService;
use App\Services\Pos\AuthorizationService;
use App\Services\Pos\CheckoutService;
use App\Services\Pos\PrintJobDispatcher;
use App\Services\Pos\ReceiptFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/** Caja: estado e informe X, apertura, cierre Z, impresión de informes y cajón. */
class CashController extends Controller
{
    public function __construct(
        private CashSessionService $cash,
        private CashReportService $reports,
        private AuthorizationService $authorization,
    ) {}

    public function show(): JsonResponse
    {
        $session = CheckoutService::openSession();

        return response()->json(['data' => $session ? $this->session($session) : null]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_float' => ['required', 'integer', 'min:0'],
            'breakdown' => ['nullable', 'array'],
            'authorization_token' => ['nullable', 'string'],
        ]);
        $manager = $this->authorization->require(AuthorizationAction::OpenCash, $request->user(), $data['authorization_token'] ?? null);

        $session = $this->cash->open($manager, $data['opening_float'], $data['breakdown'] ?? null);

        return response()->json(['data' => $this->session($session)], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'integer', 'min:0'],
            'breakdown' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
            'force' => ['boolean'],
            'print' => ['boolean'],
            'authorization_token' => ['nullable', 'string'],
        ]);
        $session = CheckoutService::openSession() ?? throw new LogicException('No hay ninguna caja abierta.');
        $manager = $this->authorization->require(AuthorizationAction::CloseCash, $request->user(), $data['authorization_token'] ?? null, $session);

        $session = $this->cash->close($session, $manager, $data['counted_cash'], $data['notes'] ?? null, $data['breakdown'] ?? null, $data['force'] ?? false);

        if ($data['print'] ?? true) {
            $this->printReport($session->z_report, true, $session, $request);
        }

        return response()->json(['data' => ['id' => $session->id, 'z_number' => $session->z_number, 'report' => $session->z_report]]);
    }

    public function printX(Request $request): JsonResponse
    {
        $session = CheckoutService::openSession() ?? throw new LogicException('No hay ninguna caja abierta.');
        $this->printReport($this->reports->build($session), false, $session, $request);

        return response()->json(['status' => 'queued']);
    }

    public function openDrawer(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate(['authorization_token' => ['nullable', 'string'], 'reason' => ['nullable', 'string', 'max:120']]);
        $this->authorization->require(AuthorizationAction::OpenDrawer, $request->user(), $data['authorization_token'] ?? null, CheckoutService::openSession(), $data['reason'] ?? null);
        $checkout->openDrawer($request->user());

        return response()->json(['status' => 'queued']);
    }

    /** Tickets de la caja abierta (o de hoy si no hay), los más recientes primero. */
    public function tickets(): JsonResponse
    {
        $session = CheckoutService::openSession();
        $tickets = Ticket::query()
            ->when($session, fn ($q) => $q->where('cash_session_id', $session->id), fn ($q) => $q->whereDate('issued_at', today()))
            ->with(['order.table', 'issuedBy', 'payments', 'rectifiedTicket'])
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $tickets->map(fn (Ticket $t) => TicketController::summary($t))]);
    }

    private function printReport(array $report, bool $final, CashSession $session, Request $request): void
    {
        $printer = PrintJobDispatcher::ticketPrinter() ?? throw new LogicException('No hay ninguna impresora de tickets activa.');
        app(PrintJobDispatcher::class)->dispatch(null, $printer, PrintJobKind::Report,
            app(ReceiptFormatter::class)->cashReport($report, $final, $printer->columns), by: $request->user(), session: $session);
    }

    private function session(CashSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status->value,
            'opened_at' => $session->opened_at->toIso8601String(),
            'opened_by' => $session->openedBy?->name,
            'opening_float' => $session->opening_float,
            'report' => $this->reports->build($session),
        ];
    }
}
