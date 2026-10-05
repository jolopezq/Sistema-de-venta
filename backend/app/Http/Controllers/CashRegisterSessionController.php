<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterSession;
use App\Models\CashMovement;
use App\Models\Sale;
use App\Models\SalePayment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashRegisterSessionController extends Controller
{
    /**
     * Obtiene la sesión de caja abierta HOY para el cajero autenticado.
     * Solo devuelve sesiones cuyo opened_at corresponde al día actual,
     * evitando que sesiones de días anteriores sin cerrar contaminen el arqueo de hoy.
     */
    public function active(Request $request): JsonResponse
    {
        $userId  = $request->user()->id;
        $today   = now()->toDateString(); // Fecha actual en la zona horaria de la app

        // Buscar sesión ABIERTA de HOY (no de días anteriores)
        $session = CashRegisterSession::with('cashier')
            ->where('cashier_id', $userId)
            ->where('status', 'open')
            ->whereDate('opened_at', $today)
            ->latest('opened_at')
            ->first();

        // Si es admin, puede ver cualquier sesión abierta de hoy
        if (!$session && $request->user()->isAdmin()) {
            $session = CashRegisterSession::with('cashier')
                ->where('status', 'open')
                ->whereDate('opened_at', $today)
                ->latest('opened_at')
                ->first();
        }

        if (!$session) {
            return response()->json([
                'session' => null,
                'stats'   => null,
            ]);
        }

        $stats = $this->calculateSessionStats($session);

        return response()->json([
            'session' => $session,
            'stats'   => $stats,
        ]);
    }

    /**
     * Abre un nuevo turno / sesión de caja con fondo inicial.
     * Si el usuario es cajero, el opening_amount es obligatorio y no puede ser modificado
     * (el sistema sugiere el valor del último cierre como referencia).
     * Si es admin o super_admin, puede cambiar el monto sugerido libremente.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_amount' => 'required|numeric|min:0',
            'date'           => 'nullable|date_format:Y-m-d',
        ]);

        $userId = $request->user()->id;
        $targetDate = $validated['date'] ?? now()->format('Y-m-d');

        if (!empty($validated['date']) && !$request->user()->isAdmin()) {
            abort(403, 'Solo un administrador puede abrir una caja con fecha específica.');
        }

        // Si se especifica una fecha, verificar si ya existe sesión para ese día
        if (!empty($validated['date'])) {
            $existing = CashRegisterSession::whereDate('opened_at', $targetDate)->first();
            if ($existing) {
                return response()->json([
                    'message' => 'Ya existe una sesión de caja para la fecha ' . $targetDate . ' (' . ($existing->status === 'open' ? 'abierta' : 'cerrada') . ').',
                    'session' => $existing->load('cashier'),
                    'stats'   => $this->calculateSessionStats($existing),
                ], 409);
            }
            // Desde el inicio del día: la caja retroactiva abarca todas las ventas de esa fecha
            $openedAt = Carbon::parse($targetDate)->startOfDay();
        } else {
            // Validar si ya tiene una sesión abierta hoy
            $existing = CashRegisterSession::where('cashier_id', $userId)
                ->where('status', 'open')
                ->whereDate('opened_at', now())
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Ya existe una sesión de caja abierta para este usuario.',
                    'session' => $existing->load('cashier'),
                    'stats'   => $this->calculateSessionStats($existing),
                ], 409);
            }
            $openedAt = now();
        }

        $session = DB::transaction(function () use ($userId, $validated, $openedAt) {
            $session = CashRegisterSession::create([
                'cashier_id'     => $userId,
                'opening_amount' => $validated['opening_amount'],
                'status'         => 'open',
                'opened_at'      => $openedAt,
            ]);

            // Caja abierta con fecha pasada: vincular las ventas de ese día que no tenían sesión
            $orphanSales = Sale::whereNull('cash_register_session_id')
                ->whereBetween('created_at', [$openedAt, $openedAt->copy()->endOfDay()]);
            if (!$session->cashier->isAdmin()) {
                $orphanSales->where('cashier_id', $userId);
            }
            // toBase(): sin tocar updated_at, para que el KDS no vuelva a mostrar pedidos viejos
            $orphanSales->toBase()->update(['cash_register_session_id' => $session->id]);

            return $session;
        });

        $session->load('cashier');

        // Consultar el último cierre para sugerir el fondo del próximo turno
        $lastClosedSession = CashRegisterSession::where('status', 'closed')
            ->whereNotNull('cash_left_for_next')
            ->where('opened_at', '<', $openedAt)
            ->orderByDesc('opened_at')
            ->first();
        $suggestedForNext = $lastClosedSession ? (float) $lastClosedSession->cash_left_for_next : 0.0;

        return response()->json([
            'message'           => 'Caja abierta exitosamente para el ' . $targetDate . '.',
            'session'           => $session,
            'stats'             => $this->calculateSessionStats($session),
            'suggested_for_next' => $suggestedForNext,
        ], 201);
    }

    /**
     * Cierra el turno de caja realizando el arqueo, distribución de efectivo y cálculo de diferencias.
     */
    public function close(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession);

        if ($cashSession->status === 'closed') {
            return response()->json([
                'message' => 'Esta sesión de caja ya fue cerrada previamente.',
                'session' => $cashSession,
            ], 400);
        }

        $validated = $request->validate([
            'actual_closing'     => 'required|numeric|min:0',
            'cash_withdrawn'     => 'required|numeric|min:0',
            'cash_left_for_next' => 'required|numeric|min:0',
            'bill_breakdown'     => 'nullable|array',
            'diff_note'          => 'nullable|string|max:1000',
        ]);

        // Validar que withdrawn + left_for_next == actual_closing (tolerancia ±0.01)
        $sumDistribution = round($validated['cash_withdrawn'] + $validated['cash_left_for_next'], 2);
        if (abs($sumDistribution - $validated['actual_closing']) > 0.01) {
            return response()->json([
                'message' => 'La suma de "Monto Retirado" + "Fondo para mañana" debe ser igual al efectivo contado (±Bs 0.01).',
            ], 422);
        }

        $stats = $this->calculateSessionStats($cashSession);

        $expectedClosing = $stats['expected_cash'];
        $actualClosing   = (float) $validated['actual_closing'];
        $difference      = round($actualClosing - $expectedClosing, 2);

        $diffNote = $validated['diff_note'] ?? null;
        if ($difference < 0 && empty($diffNote)) {
            $diffNote = 'Faltante de Bs ' . number_format(abs($difference), 2) . ' registrado en cierre.';
        }

        $cashSession->update([
            'expected_closing'   => $expectedClosing,
            'actual_closing'     => $actualClosing,
            'difference'         => $difference,
            'total_expenses'     => $stats['total_expenses'],
            'cash_withdrawn'     => $validated['cash_withdrawn'],
            'cash_left_for_next' => $validated['cash_left_for_next'],
            'bill_breakdown'     => $validated['bill_breakdown'] ?? null,
            'diff_note'          => $diffNote,
            'status'             => 'closed',
            'closed_at'          => now(),
        ]);

        $cashSession->load('cashier');
        $report = $this->buildReportPayload($cashSession, $stats);

        return response()->json([
            'message' => 'Turno de caja cerrado exitosamente.',
            'session' => $cashSession,
            'report'  => $report,
        ]);
    }

    /**
     * Genera el reporte estructurado de la sesión para impresión o consulta.
     */
    public function report(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession);
        $cashSession->load('cashier');
        $stats  = $this->calculateSessionStats($cashSession);
        $report = $this->buildReportPayload($cashSession, $stats);

        return response()->json($report);
    }

    /**
     * Registra un egreso o ingreso extraordinario de efectivo en una sesión de caja abierta.
     * Accesible para cualquier usuario autenticado (cajero, admin, super_admin).
     * El movimiento queda auditado con user_id y created_at.
     */
    public function recordMovement(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession);

        if ($cashSession->status !== 'open') {
            return response()->json([
                'message' => 'Solo se pueden registrar movimientos en sesiones de caja abiertas.',
            ], 422);
        }

        $validated = $request->validate([
            'type'           => 'required|in:expense,extra_income',
            'payment_method' => 'nullable|in:cash,qr,card',
            'amount'         => 'required|numeric|min:0.01',
            'description'    => 'required|string|min:3|max:500',
            'sale_id'        => 'nullable|uuid|exists:sales,id',
        ]);

        $movement = DB::transaction(function () use ($validated, $request, $cashSession) {
            return CashMovement::create([
                'cash_register_session_id' => $cashSession->id,
                'user_id'                  => $request->user()->id,
                'type'                     => $validated['type'],
                'payment_method'           => $validated['payment_method'] ?? 'cash',
                'amount'                   => $validated['amount'],
                'description'              => $validated['description'],
                'sale_id'                  => $validated['sale_id'] ?? null,
            ]);
        });

        // Recalcular stats en tiempo real para devolver el estado actualizado de la gaveta
        $stats = $this->calculateSessionStats($cashSession);

        return response()->json([
            'message'  => 'Movimiento de caja registrado correctamente.',
            'movement' => $movement->load('user:id,name'),
            'stats'    => $stats,
        ], 201);
    }

    /**
     * Listado de movimientos de una sesión de caja.
     */
    public function movements(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession);

        $movements = $cashSession->movements()
            ->with('user:id,name')
            ->latest('created_at')
            ->get();

        return response()->json($movements);
    }

    /**
     * Detalle y estadísticas en tiempo real de una sesión de caja específica.
     */
    public function show(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession);
        $cashSession->load('cashier');
        return response()->json([
            'session' => $cashSession,
            'stats'   => $this->calculateSessionStats($cashSession),
        ]);
    }

    /**
     * Historial de sesiones de caja (filtrable por fecha y cajero).
     */
    public function index(Request $request): JsonResponse
    {
        $query = CashRegisterSession::with('cashier')->latest('opened_at');

        if ($date = $request->date) {
            $query->whereDate('opened_at', $date);
        }

        if ($from = $request->from) {
            $query->where('opened_at', '>=', $from);
        }

        if ($to = $request->to) {
            $query->where('opened_at', '<=', $to . ' 23:59:59');
        }

        if ($cashierId = $request->cashier_id) {
            $query->where('cashier_id', $cashierId);
        }

        // Un cajero solo puede consultar sus propias sesiones
        if (!$request->user()->isAdmin()) {
            $query->where('cashier_id', $request->user()->id);
        }

        if ($status = $request->status) {
            $query->where('status', $status);
        }

        $sessions = $query->paginate($request->per_page ?? 20);

        $sessions->getCollection()->transform(function ($session) {
            $session->stats = $this->calculateSessionStats($session);
            return $session;
        });

        return response()->json($sessions);
    }

    /**
     * Solo el cajero dueño de la sesión o un administrador pueden operar sobre ella.
     */
    private function ensureCanAccess(Request $request, CashRegisterSession $session): void
    {
        $user = $request->user();

        if (!$user->isAdmin() && (int) $session->cashier_id !== (int) $user->id) {
            abort(403, 'No tienes permiso para operar sobre la caja de otro usuario.');
        }
    }

    /**
     * Helper para calcular estadísticas en tiempo real de una sesión.
     * Ecuación: expected_cash = opening_amount + cash_sales - cash_expenses + cash_extra_income
     *
     * Las ventas se toman por su vínculo con la sesión (cash_register_session_id),
     * asignado al registrarse cada venta (ver CashSessionResolver).
     */
    private function calculateSessionStats(CashRegisterSession $session): array
    {
        $salesQuery = Sale::where('cash_register_session_id', $session->id);

        $completedSales = (clone $salesQuery)->where('status', 'completed')->get();
        $voidedSales    = (clone $salesQuery)->where('status', 'voided')->get();

        $completedSaleIds = $completedSales->pluck('id');

        // Pagos agrupados por método para ventas completadas
        $payments = SalePayment::whereIn('sale_id', $completedSaleIds)
            ->select('method', DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->pluck('total', 'method')
            ->toArray();

        $cashSales  = (float) ($payments['cash'] ?? 0);
        $qrSales    = (float) ($payments['qr'] ?? 0);
        $cardSales  = (float) ($payments['card'] ?? 0);
        $otherSales = 0.0;

        foreach ($payments as $method => $amt) {
            if (!in_array($method, ['cash', 'qr', 'card'])) {
                $otherSales += (float) $amt;
            }
        }

        // Sumar egresos de caja
        $totalExpenses = (float) CashMovement::where('cash_register_session_id', $session->id)
            ->where('type', 'expense')
            ->sum('amount');

        $cashExpenses = (float) CashMovement::where('cash_register_session_id', $session->id)
            ->where('type', 'expense')
            ->where(function ($q) {
                $q->whereNull('payment_method')
                  ->orWhere('payment_method', 'cash');
            })
            ->sum('amount');

        $nonCashExpenses = (float) CashMovement::where('cash_register_session_id', $session->id)
            ->where('type', 'expense')
            ->whereIn('payment_method', ['qr', 'card'])
            ->sum('amount');

        // Sumar ingresos extraordinarios
        $extraIncome = (float) CashMovement::where('cash_register_session_id', $session->id)
            ->where('type', 'extra_income')
            ->sum('amount');

        $cashExtraIncome = (float) CashMovement::where('cash_register_session_id', $session->id)
            ->where('type', 'extra_income')
            ->where(function ($q) {
                $q->whereNull('payment_method')
                  ->orWhere('payment_method', 'cash');
            })
            ->sum('amount');

        $totalSalesAmount = (float) $completedSales->sum('total_amount');
        $totalDiscounts   = (float) $completedSales->sum('discount_amount');

        // Ecuación del arqueo: fondo + ventas efectivo - egresos efectivo + ingresos_extra efectivo
        $expectedCash = round((float) $session->opening_amount + $cashSales - $cashExpenses + $cashExtraIncome, 2);

        return [
            'sales_count'       => $completedSales->count(),
            'voided_count'      => $voidedSales->count(),
            'total_sales'       => $totalSalesAmount,
            'total_discounts'   => $totalDiscounts,
            'cash_sales'        => $cashSales,
            'qr_sales'          => $qrSales,
            'card_sales'        => $cardSales,
            'other_sales'       => $otherSales,
            'opening_amount'    => (float) $session->opening_amount,
            'total_expenses'    => $totalExpenses,
            'cash_expenses'     => $cashExpenses,
            'non_cash_expenses' => $nonCashExpenses,
            'extra_income'      => $extraIncome,
            'cash_extra_income' => $cashExtraIncome,
            'expected_cash'     => $expectedCash,
        ];
    }

    /**
     * Estructura el payload del reporte de cierre incluyendo distribución de efectivo y egresos.
     */
    private function buildReportPayload(CashRegisterSession $session, array $stats): array
    {
        $durationFormatted = '';
        if ($session->opened_at && $session->closed_at) {
            $diffMinutes       = $session->opened_at->diffInMinutes($session->closed_at);
            $hours             = floor($diffMinutes / 60);
            $mins              = $diffMinutes % 60;
            $durationFormatted = "{$hours}h {$mins}m";
        }

        // Cargar lista de movimientos para el ticket de cierre
        $movements = $session->movements()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'id'             => $m->id,
                'type'           => $m->type,
                'payment_method' => $m->payment_method ?? 'cash',
                'amount'         => (float) $m->amount,
                'description'    => $m->description,
                'user'           => $m->user?->name,
                'created_at'     => $m->created_at?->toIso8601String(),
            ])->toArray();

        return [
            'session'           => $session,
            'cashier_name'      => $session->cashier?->name ?? 'Cajero',
            'opened_at'         => $session->opened_at?->toIso8601String(),
            'closed_at'         => $session->closed_at?->toIso8601String(),
            'duration'          => $durationFormatted,
            'opening_amount'    => (float) $session->opening_amount,
            'expected_closing'  => (float) ($session->expected_closing ?? $stats['expected_cash']),
            'actual_closing'    => (float) ($session->actual_closing ?? 0),
            'difference'        => (float) ($session->difference ?? 0),
            'total_expenses'    => (float) ($session->total_expenses ?? $stats['total_expenses']),
            'cash_withdrawn'    => (float) ($session->cash_withdrawn ?? 0),
            'cash_left_for_next'=> (float) ($session->cash_left_for_next ?? 0),
            'diff_note'         => $session->diff_note,
            'bill_breakdown'    => $session->bill_breakdown,
            'movements'         => $movements,
            'summary'           => $stats,
        ];
    }
}
