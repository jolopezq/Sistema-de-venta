<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashRegisterSessionController extends Controller
{
    /**
     * Obtiene la sesión de caja actualmente abierta para el cajero autenticado.
     */
    public function active(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $session = CashRegisterSession::with('cashier')
            ->where('cashier_id', $userId)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        // Si no encuentra por cajero directo pero es admin, puede ver si hay alguna abierta
        if (!$session && $request->user()->isAdmin()) {
            $session = CashRegisterSession::with('cashier')
                ->where('status', 'open')
                ->latest('opened_at')
                ->first();
        }

        if (!$session) {
            return response()->json([
                'session' => null,
                'stats' => null,
            ]);
        }

        $stats = $this->calculateSessionStats($session);

        return response()->json([
            'session' => $session,
            'stats' => $stats,
        ]);
    }

    /**
     * Abre un nuevo turno / sesión de caja con fondo inicial.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_amount' => 'required|numeric|min:0',
        ]);

        $userId = $request->user()->id;

        // Validar si ya tiene una sesión abierta
        $existing = CashRegisterSession::where('cashier_id', $userId)
            ->where('status', 'open')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Ya existe una sesión de caja abierta para este usuario.',
                'session' => $existing,
                'stats' => $this->calculateSessionStats($existing),
            ], 409);
        }

        $session = CashRegisterSession::create([
            'cashier_id' => $userId,
            'opening_amount' => $validated['opening_amount'],
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $session->load('cashier');

        return response()->json([
            'message' => 'Caja abierta exitosamente.',
            'session' => $session,
            'stats' => $this->calculateSessionStats($session),
        ], 201);
    }

    /**
     * Cierra el turno de caja realizando el arqueo y cálculo de diferencias.
     */
    public function close(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        if ($cashSession->status === 'closed') {
            return response()->json([
                'message' => 'Esta sesión de caja ya fue cerrada previamente.',
                'session' => $cashSession,
            ], 400);
        }

        $validated = $request->validate([
            'actual_closing' => 'required|numeric|min:0',
            'bill_breakdown' => 'nullable|array',
            'diff_note' => 'nullable|string|max:1000',
        ]);

        $stats = $this->calculateSessionStats($cashSession);

        $expectedClosing = $stats['expected_cash'];
        $actualClosing = (float) $validated['actual_closing'];
        $difference = round($actualClosing - $expectedClosing, 2);

        // Si hay faltante considerable y no mandó nota, se puede registrar
        $diffNote = $validated['diff_note'] ?? null;
        if ($difference < 0 && empty($diffNote)) {
            $diffNote = 'Faltante de Bs ' . number_format(abs($difference), 2) . ' registrado en cierre.';
        }

        $cashSession->update([
            'expected_closing' => $expectedClosing,
            'actual_closing' => $actualClosing,
            'difference' => $difference,
            'bill_breakdown' => $validated['bill_breakdown'] ?? null,
            'diff_note' => $diffNote,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $cashSession->load('cashier');
        $report = $this->buildReportPayload($cashSession, $stats);

        return response()->json([
            'message' => 'Turno de caja cerrado exitosamente.',
            'session' => $cashSession,
            'report' => $report,
        ]);
    }

    /**
     * Genera el reporte estructurado de la sesión para impresión o consulta.
     */
    public function report(CashRegisterSession $cashSession): JsonResponse
    {
        $cashSession->load('cashier');
        $stats = $this->calculateSessionStats($cashSession);
        $report = $this->buildReportPayload($cashSession, $stats);

        return response()->json($report);
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

        if ($status = $request->status) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    /**
     * Helper para calcular estadísticas en tiempo real de una sesión.
     */
    private function calculateSessionStats(CashRegisterSession $session): array
    {
        $openedAt = $session->opened_at;
        $closedAt = $session->closed_at ?? now();

        $salesQuery = Sale::where('cashier_id', $session->cashier_id)
            ->whereBetween('created_at', [$openedAt, $closedAt]);

        $completedSales = (clone $salesQuery)->where('status', 'completed')->get();
        $voidedSales = (clone $salesQuery)->where('status', 'voided')->get();

        $completedSaleIds = $completedSales->pluck('id');

        // Pagos agrupados por método para ventas completadas
        $payments = SalePayment::whereIn('sale_id', $completedSaleIds)
            ->select('method', DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->pluck('total', 'method')
            ->toArray();

        $cashSales = (float) ($payments['cash'] ?? 0);
        $qrSales = (float) ($payments['qr'] ?? 0);
        $cardSales = (float) ($payments['card'] ?? 0);
        $otherSales = 0.0;

        foreach ($payments as $method => $amt) {
            if (!in_array($method, ['cash', 'qr', 'card'])) {
                $otherSales += (float) $amt;
            }
        }

        $totalSalesAmount = (float) $completedSales->sum('total_amount');
        $totalDiscounts = (float) $completedSales->sum('discount_amount');
        $expectedCash = round((float) $session->opening_amount + $cashSales, 2);

        return [
            'sales_count' => $completedSales->count(),
            'voided_count' => $voidedSales->count(),
            'total_sales' => $totalSalesAmount,
            'total_discounts' => $totalDiscounts,
            'cash_sales' => $cashSales,
            'qr_sales' => $qrSales,
            'card_sales' => $cardSales,
            'other_sales' => $otherSales,
            'opening_amount' => (float) $session->opening_amount,
            'expected_cash' => $expectedCash,
        ];
    }

    /**
     * Estructura el payload del reporte de cierre.
     */
    private function buildReportPayload(CashRegisterSession $session, array $stats): array
    {
        $durationFormatted = '';
        if ($session->opened_at && $session->closed_at) {
            $diffMinutes = $session->opened_at->diffInMinutes($session->closed_at);
            $hours = floor($diffMinutes / 60);
            $mins = $diffMinutes % 60;
            $durationFormatted = "{$hours}h {$mins}m";
        }

        return [
            'session' => $session,
            'cashier_name' => $session->cashier?->name ?? 'Cajero',
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'duration' => $durationFormatted,
            'opening_amount' => (float) $session->opening_amount,
            'expected_closing' => (float) ($session->expected_closing ?? $stats['expected_cash']),
            'actual_closing' => (float) ($session->actual_closing ?? 0),
            'difference' => (float) ($session->difference ?? 0),
            'diff_note' => $session->diff_note,
            'bill_breakdown' => $session->bill_breakdown,
            'summary' => $stats,
        ];
    }
}
