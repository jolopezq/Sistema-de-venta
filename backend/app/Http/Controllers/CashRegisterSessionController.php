<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CashRegisterSession;
use App\Models\CashMovement;
use App\Models\CashSessionClosing;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\AuditLogger;
use App\Services\CashSessionResolver;
use App\Services\DiscountPolicy;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashRegisterSessionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}
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

        // Si tiene permiso para ver cajas de otros, puede ver cualquier sesión abierta de hoy
        if (!$session && $this->permissionService->can($request->user(), 'register_others', 'read')) {
            $session = CashRegisterSession::with('cashier')
                ->where('status', 'open')
                ->whereDate('opened_at', $today)
                ->latest('opened_at')
                ->first();
        }

        // Caja que recibiría una venta de este usuario ahora mismo, con la misma regla que se aplica al
        // guardar la venta (su propia caja o la caja compartida de un admin). El POS la usa para no cobrar
        // sin caja: una venta sin caja no entra a ningún arqueo.
        $salesSession = app(CashSessionResolver::class)->resolve($userId, now());

        if (!$session) {
            return response()->json([
                'session'          => null,
                'stats'            => null,
                'sales_session_id' => $salesSession?->id,
            ]);
        }

        $stats = $this->calculateSessionStats($session);

        return response()->json([
            'session'          => $session,
            'stats'            => $stats,
            'sales_session_id' => $salesSession?->id,
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
            'date'           => 'nullable|date_format:Y-m-d|before_or_equal:today',
            // Caja de un día pasado a nombre del cajero real (día sin sistema). Solo con fecha.
            'cashier_id'     => 'nullable|integer|exists:users,id',
            // Motivo si el fondo no coincide con lo que dejó la caja anterior (plan de conciliación, E)
            'opening_note'   => 'nullable|string|max:255',
        ]);

        $userId = $request->user()->id;
        if (!empty($validated['date']) && !empty($validated['cashier_id'])) {
            $owner = \App\Models\User::find($validated['cashier_id']);
            if (!in_array($owner?->role, ['super_admin', 'admin', 'cashier'], true)) {
                return response()->json(['message' => 'La caja solo puede quedar a nombre de un cajero o un administrador.'], 422);
            }
            $userId = $owner->id;
        }
        $targetDate = $validated['date'] ?? now()->format('Y-m-d');

        if (!empty($validated['date']) && !$this->permissionService->can($request->user(), 'sales_history', 'edit')) {
            abort(403, 'Abrir una caja con fecha pasada requiere el permiso «Ventas de días pasados».');
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

        // El fondo tiene que ser lo que dejó la caja cerrada anterior; si no, se pide el motivo (plan de conciliación,
        // E). Un fondo de 0 con el fondo cargado como «ingreso extra», o 4 de más, descuadraba el mes sin aviso.
        $previous = $this->previousClosedSession($openedAt);
        $openingNote = trim((string) ($validated['opening_note'] ?? ''));
        $mismatch = $previous && abs((float) $validated['opening_amount'] - (float) $previous->cash_left_for_next) > 0.005;
        if ($mismatch && mb_strlen($openingNote) < 5) {
            return response()->json([
                'message'       => sprintf(
                    'La caja del %s dejó Bs %s para el día siguiente. Si abres con otro monto, escribe el motivo (al menos 5 caracteres).',
                    $previous->opened_at->format('d/m'),
                    number_format((float) $previous->cash_left_for_next, 2, ',', '.')
                ),
                'reason'        => 'opening_mismatch',
                'expected'      => (float) $previous->cash_left_for_next,
                'previous_date' => $previous->opened_at->toDateString(),
            ], 422);
        }

        $session = DB::transaction(function () use ($userId, $validated, $openedAt, $mismatch, $openingNote) {
            $session = CashRegisterSession::create([
                'cashier_id'     => $userId,
                'opening_amount' => $validated['opening_amount'],
                'opening_note'   => $mismatch ? $openingNote : null,
                'status'         => 'open',
                'opened_at'      => $openedAt,
            ]);

            // Vincular las ventas de ese día que no tenían caja: las de una caja con fecha pasada y, en la de hoy,
            // las cobradas antes de abrirla (sin conexión); sin caja no entran a ningún arqueo
            $orphanSales = Sale::whereNull('cash_register_session_id')
                ->whereBetween('created_at', [$openedAt->copy()->startOfDay(), $openedAt->copy()->endOfDay()]);
            if (!$session->cashier->isAdmin()) {
                $orphanSales->where('cashier_id', $userId);
            }
            // toBase(): sin tocar updated_at, para que el KDS no vuelva a mostrar pedidos viejos
            $orphanSales->toBase()->update(['cash_register_session_id' => $session->id]);

            return $session;
        });

        $session->load('cashier');

        if ($mismatch) {
            AuditLogger::log(
                'cash_session_opening_mismatch',
                'Sales',
                sprintf(
                    'Caja #%d del %s abierta por %s con Bs %s; la caja del %s dejó Bs %s. Motivo: %s',
                    $session->id,
                    $openedAt->format('d/m/Y'),
                    $request->user()->name,
                    number_format((float) $session->opening_amount, 2),
                    $previous->opened_at->format('d/m/Y'),
                    number_format((float) $previous->cash_left_for_next, 2),
                    $openingNote
                ),
                $request->user()->id
            );
        }

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
            'unclosed'          => $this->unclosedSessions($request, $session->id),
        ], 201);
    }

    /**
     * Antes de abrir una caja (hoy o un día pasado): con qué fondo abrir y qué cajas de días anteriores siguen
     * abiertas (plan de conciliación, D y E).
     */
    public function openingInfo(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => 'nullable|date_format:Y-m-d|before_or_equal:today']);
        $openedAt = !empty($validated['date']) && $validated['date'] !== now()->toDateString()
            ? Carbon::parse($validated['date'])->startOfDay()
            : now();
        $previous = $this->previousClosedSession($openedAt);

        return response()->json([
            'date'              => $openedAt->toDateString(),
            'suggested_opening' => $previous ? (float) $previous->cash_left_for_next : null,
            'previous'          => $previous ? [
                'session_id'    => $previous->id,
                'date'          => $previous->opened_at->toDateString(),
                'cashier'       => $previous->cashier?->name,
                'left_for_next' => (float) $previous->cash_left_for_next,
            ] : null,
            'unclosed'          => $this->unclosedSessions($request),
        ]);
    }

    /** La última caja cerrada que se abrió antes de ese momento, con lo que dejó para el día siguiente. */
    private function previousClosedSession(Carbon $openedAt): ?CashRegisterSession
    {
        return CashRegisterSession::with('cashier:id,name')
            ->where('status', 'closed')
            ->whereNotNull('cash_left_for_next')
            ->where('opened_at', '<', $openedAt)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Cajas de días anteriores que siguen abiertas: su efectivo no se sabe si se retiró ni con qué fondo siguió el
     * día siguiente. Quien no ve cajas ajenas solo ve las suyas.
     *
     * @return list<array{session_id: int, date: string, cashier: ?string}>
     */
    private function unclosedSessions(Request $request, ?int $except = null): array
    {
        $query = CashRegisterSession::with('cashier:id,name')
            ->where('status', 'open')
            ->where('opened_at', '<', now()->startOfDay())
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->orderBy('opened_at');
        if (!$this->permissionService->can($request->user(), 'register_others', 'read')) {
            $query->where('cashier_id', $request->user()->id);
        }

        return $query->get()->map(fn (CashRegisterSession $s) => [
            'session_id' => $s->id,
            'date'       => $s->opened_at->toDateString(),
            'cashier'    => $s->cashier?->name,
        ])->all();
    }

    /**
     * Valida el desglose del conteo. Devuelve el mensaje de error o null si es válido (o vacío).
     * Se hace a mano: la clave «0.50» lleva un punto y chocaría con la sintaxis de comodines de Laravel.
     */
    private function breakdownError(?array $breakdown, float $actualClosing): ?string
    {
        if (empty($breakdown)) {
            return null;
        }
        $allowed = ['200', '100', '50', '20', '10', '5', '2', '1', '0.5', '0.50'];
        $sum = 0.0;
        foreach ($breakdown as $denom => $qty) {
            $key = (string) $denom;
            if (!in_array($key, $allowed, true)) {
                return "El desglose del conteo tiene una denominación no válida ({$key}).";
            }
            if (is_bool($qty) || !is_numeric($qty) || (float) $qty != floor((float) $qty) || $qty < 0 || $qty > 9999) {
                return "La cantidad de piezas de Bs {$key} debe ser un entero entre 0 y 9999.";
            }
            $sum += (float) $key * (int) $qty;
        }
        if (abs(round($sum, 2) - $actualClosing) > 0.01) {
            return 'El desglose de billetes y monedas (Bs ' . number_format($sum, 2, '.', '') . ') no coincide con el efectivo contado (Bs '
                . number_format($actualClosing, 2, '.', '') . ').';
        }
        return null;
    }

    /**
     * Cierra el turno de caja realizando el arqueo, distribución de efectivo y cálculo de diferencias.
     */
    public function close(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession, 'edit');

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
            // Efectivo esperado que el cajero vio al contar: si cambió, no se cierra a ciegas
            'expected_seen'      => 'nullable|numeric',
            // Ventas del dispositivo que aún no llegaron al servidor (pendientes o rechazadas)
            'unsynced_sales'     => 'nullable|integer|min:0',
            'force_reason'       => 'nullable|string|max:500',
            // Caja reabierta para corregir: motivo obligatorio del nuevo cierre
            'correction_reason'  => 'nullable|string|max:500',
            // Cobros QR del día que muestra la app del banco (plan de conciliación, G). Opcional.
            'qr_bank_reported'   => 'nullable|numeric|min:0|max:9999999',
        ]);

        $isCorrection = $cashSession->reopened_at !== null;
        $correctionReason = trim((string) ($validated['correction_reason'] ?? ''));
        if ($isCorrection) {
            if (!$this->permissionService->can($request->user(), 'register_corrections', 'edit')) {
                abort(403, 'Volver a cerrar una caja reabierta requiere el permiso «Corregir cajas cerradas».');
            }
            if (mb_strlen($correctionReason) < 10) {
                return response()->json(['message' => 'Escribe el motivo de la corrección (al menos 10 caracteres).'], 422);
            }
        }

        // Validar que withdrawn + left_for_next == actual_closing (tolerancia ±0.01)
        $sumDistribution = round($validated['cash_withdrawn'] + $validated['cash_left_for_next'], 2);
        if (abs($sumDistribution - $validated['actual_closing']) > 0.01) {
            return response()->json([
                'message' => 'La suma de "Monto Retirado" + "Fondo para mañana" debe ser igual al efectivo contado (±Bs 0.01).',
            ], 422);
        }

        // Si viene el desglose de billetes y monedas, debe ser coherente con el efectivo contado:
        // solo denominaciones de Bolivia, cantidades enteras 0–9999 y suma igual a «actual_closing».
        $breakdownError = $this->breakdownError($validated['bill_breakdown'] ?? null, (float) $validated['actual_closing']);
        if ($breakdownError !== null) {
            return response()->json(['message' => $breakdownError], 422);
        }

        // Con ventas sin sincronizar el arqueo saldría incompleto: el cajero no cierra; un admin puede
        // forzar el cierre dejando el motivo, que queda en la nota de diferencia y en la auditoría.
        $unsynced    = (int) ($validated['unsynced_sales'] ?? 0);
        $forceReason = trim((string) ($validated['force_reason'] ?? ''));
        if ($unsynced > 0) {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'message' => "Hay {$unsynced} " . ($unsynced === 1 ? 'venta' : 'ventas') . ' de este dispositivo sin sincronizar. '
                        . 'Sincronízalas antes de cerrar o pide a un administrador que cierre la caja.',
                ], 422);
            }
            if (mb_strlen($forceReason) < 5) {
                return response()->json([
                    'message' => 'Para cerrar con ventas sin sincronizar escribe el motivo (al menos 5 caracteres).',
                ], 422);
            }
        }

        $stats = $this->calculateSessionStats($cashSession);

        $expectedClosing = $stats['expected_cash'];

        if (isset($validated['expected_seen']) && abs((float) $validated['expected_seen'] - $expectedClosing) > 0.005) {
            return response()->json([
                'message' => 'Las cifras de la caja cambiaron mientras contabas (entró una venta o un movimiento). '
                    . 'Revisa el efectivo esperado y confirma otra vez.',
                'stats'   => $stats,
            ], 409);
        }

        $actualClosing   = (float) $validated['actual_closing'];
        $difference      = round($actualClosing - $expectedClosing, 2);

        $diffNote = $validated['diff_note'] ?? null;
        if ($difference < 0 && empty($diffNote)) {
            $diffNote = 'Faltante de Bs ' . number_format(abs($difference), 2) . ' registrado en cierre.';
        }

        if ($unsynced > 0) {
            $forcedNote = "Cierre forzado con {$unsynced} " . ($unsynced === 1 ? 'venta' : 'ventas') . " sin sincronizar: {$forceReason}";
            $diffNote   = trim($forcedNote . ($diffNote ? "\n" . $diffNote : ''));
            AuditLogger::log(
                'cash_close_forced',
                'Sales',
                "Caja #{$cashSession->id} cerrada por {$request->user()->name} con {$unsynced} "
                    . ($unsynced === 1 ? 'venta' : 'ventas') . " sin sincronizar. Motivo: {$forceReason}",
                $request->user()->id
            );
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
            // Si el cierre no lo trae (p. ej. al corregir sin volver a mirarlo), se conserva el anotado antes
            'qr_bank_reported'   => array_key_exists('qr_bank_reported', $validated) && $validated['qr_bank_reported'] !== null
                ? round((float) $validated['qr_bank_reported'], 2)
                : $cashSession->qr_bank_reported,
            'status'             => 'closed',
            // Al corregir, el cierre conserva la fecha y hora del cierre original
            'closed_at'          => $cashSession->closed_at ?? now(),
            'reopened_at'        => null,
        ]);

        if ($isCorrection) {
            $previous = $cashSession->closings()->whereNull('reclosed_at')->latest('id')->first();
            $previous?->update([
                'reclosed_by'    => $request->user()->id,
                'reclosed_at'    => now(),
                'reclose_reason' => $correctionReason,
            ]);
            AuditLogger::log(
                'cash_session_reclosed',
                'Sales',
                sprintf(
                    'Caja #%d del %s corregida y cerrada otra vez por %s. Teórico Bs %s → Bs %s; contado Bs %s → Bs %s; diferencia Bs %s → Bs %s. Motivo: %s',
                    $cashSession->id,
                    $cashSession->opened_at->format('d/m/Y'),
                    $request->user()->name,
                    number_format((float) $previous?->expected_closing, 2),
                    number_format($expectedClosing, 2),
                    number_format((float) $previous?->actual_closing, 2),
                    number_format($actualClosing, 2),
                    number_format((float) $previous?->difference, 2),
                    number_format($difference, 2),
                    $correctionReason
                ),
                $request->user()->id
            );
        }

        $cashSession->load('cashier');
        $report = $this->buildReportPayload($cashSession, $stats);

        return response()->json([
            'message' => 'Turno de caja cerrado exitosamente.',
            'session' => $cashSession,
            'report'  => $report,
        ]);
    }

    /**
     * Reabre una caja cerrada para corregirla (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md §3.4): guarda su cierre en el
     * historial y la deja abierta para registrar lo que faltó y volver a cerrarla con un motivo. El POS no la ve
     * (solo muestra cajas de hoy) y no recibe ventas de otros días. Permiso «Corregir cajas cerradas».
     */
    public function reopen(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|min:10|max:500'], [
            'reason.required' => 'Escribe por qué se reabre la caja.',
            'reason.min'      => 'El motivo debe tener al menos 10 caracteres.',
        ]);
        if ($cashSession->status !== 'closed') {
            return response()->json(['message' => 'Solo se puede reabrir una caja cerrada.'], 422);
        }

        $reason = trim($validated['reason']);
        DB::transaction(function () use ($cashSession, $request, $reason) {
            CashSessionClosing::create([
                'cash_register_session_id' => $cashSession->id,
                'expected_closing'         => $cashSession->expected_closing,
                'actual_closing'           => $cashSession->actual_closing,
                'difference'               => $cashSession->difference,
                'total_expenses'           => $cashSession->total_expenses,
                'cash_withdrawn'           => $cashSession->cash_withdrawn,
                'cash_left_for_next'       => $cashSession->cash_left_for_next,
                'bill_breakdown'           => $cashSession->bill_breakdown,
                'diff_note'                => $cashSession->diff_note,
                'closed_at'                => $cashSession->closed_at,
                'reopened_by'              => $request->user()->id,
                'reopened_at'              => now(),
                'reopen_reason'            => $reason,
            ]);
            $cashSession->update(['status' => 'open', 'reopened_at' => now()]);
        });

        AuditLogger::log(
            'cash_session_reopened',
            'Sales',
            sprintf(
                'Caja #%d del %s reabierta por %s para corregir. Cierre anterior: teórico Bs %s, contado Bs %s, diferencia Bs %s. Motivo: %s',
                $cashSession->id,
                $cashSession->opened_at->format('d/m/Y'),
                $request->user()->name,
                number_format((float) $cashSession->expected_closing, 2),
                number_format((float) $cashSession->actual_closing, 2),
                number_format((float) $cashSession->difference, 2),
                $reason
            ),
            $request->user()->id
        );

        $cashSession->refresh()->load('cashier');

        return response()->json([
            'message' => 'Caja reabierta para corregir.',
            'session' => $cashSession,
            'stats'   => $this->calculateSessionStats($cashSession),
        ]);
    }

    /**
     * Genera el reporte estructurado de la sesión para impresión o consulta.
     */
    public function report(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession, 'read');
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
        $this->ensureCanAccess($request, $cashSession, 'edit');

        if ($cashSession->status !== 'open') {
            return response()->json([
                'message' => 'Solo se pueden registrar movimientos en sesiones de caja abiertas.',
            ], 422);
        }

        $validated = $request->validate([
            'type'                => 'required|in:expense,extra_income',
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            // mixed: gasto dividido entre efectivo de la gaveta (cash_amount) y QR o banco (el resto)
            'payment_method'      => 'nullable|in:cash,qr,card,mixed',
            'cash_amount'         => 'nullable|numeric',
            'amount'              => 'required|numeric|min:0.01',
            'description'         => 'required|string|min:3|max:500',
            'sale_id'             => 'nullable|uuid|exists:sales,id',
            // Cuándo ocurrió: dentro del día de la caja. En una caja de un día pasado, por defecto el fin de ese día.
            'occurred_at'         => 'nullable|date',
        ]);

        $method = $validated['payment_method'] ?? 'cash';
        $cashAmount = $method === 'mixed' && isset($validated['cash_amount']) ? round((float) $validated['cash_amount'], 2) : null;
        if ($error = $this->splitError($validated['type'], $method, (float) $validated['amount'], $cashAmount)) {
            return response()->json(['message' => $error, 'errors' => ['cash_amount' => [$error]]], 422);
        }

        $validated['occurred_at'] = $this->resolveOccurredAt($cashSession, $validated['occurred_at'] ?? null);

        $movement = DB::transaction(function () use ($validated, $request, $cashSession, $method, $cashAmount) {
            return CashMovement::create([
                'cash_register_session_id' => $cashSession->id,
                'user_id'                  => $request->user()->id,
                'expense_category_id'      => $validated['expense_category_id'] ?? null,
                'type'                     => $validated['type'],
                'payment_method'           => $method,
                'cash_amount'              => $cashAmount,
                'amount'                   => $validated['amount'],
                'description'              => $validated['description'],
                'sale_id'                  => $validated['sale_id'] ?? null,
                'occurred_at'              => $validated['occurred_at'],
            ]);
        });

        // Recalcular stats en tiempo real para devolver el estado actualizado de la gaveta
        $stats = $this->calculateSessionStats($cashSession);

        return response()->json([
            'message'  => 'Movimiento de caja registrado correctamente.',
            'movement' => $movement->load(['user:id,name', 'expenseCategory:id,name,section,group_name']),
            'stats'    => $stats,
        ], 201);
    }

    /**
     * Corrige un movimiento de caja existente (método, monto, descripción, categoría contable, fecha).
     * Requiere un motivo obligatorio de 10 a 255 caracteres. En una caja cerrada, además, el permiso «Corregir cajas
     * cerradas»: el cierre se rehace con el mismo conteo (recloseWithSameCount).
     */
    public function updateMovement(Request $request, CashRegisterSession $cashSession, CashMovement $cashMovement): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession, 'edit');

        if ((int) $cashMovement->cash_register_session_id !== (int) $cashSession->id) {
            return response()->json(['message' => 'El movimiento no pertenece a esta sesión.'], 404);
        }

        // Caja cerrada: se corrige en un paso, con el permiso «Corregir cajas cerradas» (ver recloseWithSameCount)
        if ($denied = $this->closedCorrectionDenied($request, $cashSession)) {
            return $denied;
        }
        $closedCorrection = $cashSession->status === 'closed';

        if ($cashMovement->trashed()) {
            return response()->json([
                'message' => 'El movimiento ya está anulado.',
            ], 422);
        }

        $validated = $request->validate([
            'reason'              => ['required', 'string', 'min:10', 'max:255'],
            'payment_method'      => ['nullable', 'in:cash,qr,card,mixed'],
            'cash_amount'         => ['nullable', 'numeric'],
            'amount'              => ['nullable', 'numeric', 'min:0.01'],
            'description'         => ['nullable', 'string', 'min:3', 'max:500'],
            'expense_category_id' => ['nullable', 'exists:expense_categories,id'],
            'occurred_at'         => ['nullable', 'date'],
        ], [
            'reason.required' => 'El motivo de la corrección es obligatorio.',
            'reason.min'      => 'El motivo debe tener al menos 10 caracteres.',
        ]);

        $newMethod      = $validated['payment_method'] ?? $cashMovement->payment_method ?? 'cash';
        $newAmount      = array_key_exists('amount', $validated) ? round((float) $validated['amount'], 2) : (float) $cashMovement->amount;
        $newDescription = array_key_exists('description', $validated) ? trim($validated['description']) : $cashMovement->description;
        $newCategory    = array_key_exists('expense_category_id', $validated)
            ? ($cashMovement->isExpense() ? ($validated['expense_category_id'] ?: null) : null)
            : $cashMovement->expense_category_id;

        $newOccurredAt = array_key_exists('occurred_at', $validated) && !empty($validated['occurred_at'])
            ? $this->resolveOccurredAt($cashSession, $validated['occurred_at'])
            : $cashMovement->occurred_at;

        // Gasto dividido: la parte en efectivo se toma de la petición o, si no viene, la que ya tenía
        $newCashAmount = null;
        if ($newMethod === 'mixed') {
            $newCashAmount = isset($validated['cash_amount'])
                ? round((float) $validated['cash_amount'], 2)
                : ($cashMovement->cash_amount !== null ? (float) $cashMovement->cash_amount : null);
        }
        if ($error = $this->splitError($cashMovement->type, $newMethod, $newAmount, $newCashAmount)) {
            return response()->json(['message' => $error, 'errors' => ['cash_amount' => [$error]]], 422);
        }

        // Verificar si nada cambió
        $amountMatches = abs((float) $cashMovement->amount - $newAmount) < 0.005;
        $methodMatches = ($cashMovement->payment_method ?? 'cash') === $newMethod
            && abs((float) ($cashMovement->cash_amount ?? 0) - (float) ($newCashAmount ?? 0)) < 0.005;
        $descMatches   = trim((string) $cashMovement->description) === $newDescription;
        $catMatches    = (int) ($cashMovement->expense_category_id ?? 0) === (int) ($newCategory ?? 0);
        $dateMatches   = $cashMovement->occurred_at?->format('Y-m-d H:i') === $newOccurredAt?->format('Y-m-d H:i');

        if ($amountMatches && $methodMatches && $descMatches && $catMatches && $dateMatches) {
            return response()->json([
                'message' => 'No hay cambios para guardar.',
            ], 422);
        }

        $closing = null;
        $movement = DB::transaction(function () use (
            $cashMovement, $cashSession, $request, $validated, $closedCorrection, &$closing,
            $newMethod, $newCashAmount, $newAmount, $newDescription, $newCategory, $newOccurredAt,
            $amountMatches, $methodMatches, $descMatches, $catMatches, $dateMatches
        ) {
            $previous = [
                'payment_method'      => $cashMovement->payment_method ?? 'cash',
                'cash_amount'         => $cashMovement->cash_amount !== null ? (float) $cashMovement->cash_amount : null,
                'amount'              => (float) $cashMovement->amount,
                'description'         => $cashMovement->description,
                'expense_category_id' => $cashMovement->expense_category_id,
                'occurred_at'         => $cashMovement->occurred_at?->toIso8601String(),
            ];

            $cashMovement->update([
                'payment_method'      => $newMethod,
                'cash_amount'         => $newCashAmount,
                'amount'              => $newAmount,
                'description'         => $newDescription,
                'expense_category_id' => $newCategory,
                'occurred_at'         => $newOccurredAt,
                'edited_by'           => $request->user()->id,
                'edited_at'           => now(),
                'edit_reason'         => trim($validated['reason']),
                'edit_previous'       => $previous,
                'edit_count'          => (int) ($cashMovement->edit_count ?? 0) + 1,
            ]);

            // Detalle descriptivo para la auditoría
            $changesDesc = [];
            if (!$methodMatches) {
                $prevM = CashMovement::methodText($previous['payment_method'], $previous['amount'], $previous['cash_amount']);
                $nextM = CashMovement::methodText($newMethod, $newAmount, $newCashAmount);
                $changesDesc[] = "método {$prevM} → {$nextM}";
            }
            if (!$amountMatches) {
                $changesDesc[] = "monto Bs " . number_format($previous['amount'], 2, '.', '') . " → Bs " . number_format($newAmount, 2, '.', '');
            } else {
                $changesDesc[] = "monto Bs " . number_format($newAmount, 2, '.', '') . " (sin cambio)";
            }
            if (!$descMatches) {
                $changesDesc[] = "concepto actualizado";
            }
            if (!$catMatches) {
                $changesDesc[] = "categoría contable actualizada";
            }
            if (!$dateMatches) {
                $changesDesc[] = "fecha/hora actualizada";
            }

            $typeLabel = $cashMovement->type === 'expense' ? 'egreso' : 'ingreso';
            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'cash_movement_updated',
                'module'      => 'CashMovement',
                'description' => "Caja #{$cashSession->id}, {$typeLabel} #{$cashMovement->id} corregido por {$request->user()->name}: " . implode('; ', $changesDesc) . ". Motivo: " . trim($validated['reason']),
            ]);

            if ($closedCorrection) {
                $closing = $this->recloseWithSameCount(
                    $cashSession,
                    $request,
                    "{$typeLabel} #{$cashMovement->id} corregido: " . implode('; ', $changesDesc),
                    trim($validated['reason'])
                );
            }

            return $cashMovement;
        });

        $stats = $this->calculateSessionStats($cashSession);

        return response()->json([
            'message'  => $closing
                ? 'Movimiento corregido y cierre de caja rehecho con el mismo conteo.'
                : 'Movimiento de caja corregido correctamente.',
            'movement' => $movement->fresh(['user:id,name', 'expenseCategory:id,name,section,group_name', 'editedBy:id,name', 'voidedBy:id,name']),
            'stats'    => $stats,
            'closing'  => $closing,
        ]);
    }

    /**
     * Anula un movimiento de caja.
     * El movimiento queda con soft delete (deleted_at), voided_by y void_reason.
     * Deja de contar en el arqueo y en Finanzas, pero permanece visible y tachado.
     * En una caja cerrada, igual que al corregir: permiso «Corregir cajas cerradas» y cierre rehecho con el mismo conteo.
     */
    public function voidMovement(Request $request, CashRegisterSession $cashSession, CashMovement $cashMovement): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession, 'edit');

        if ((int) $cashMovement->cash_register_session_id !== (int) $cashSession->id) {
            return response()->json(['message' => 'El movimiento no pertenece a esta sesión.'], 404);
        }

        // Caja cerrada: se corrige en un paso, con el permiso «Corregir cajas cerradas» (ver recloseWithSameCount)
        if ($denied = $this->closedCorrectionDenied($request, $cashSession)) {
            return $denied;
        }
        $closedCorrection = $cashSession->status === 'closed';

        if ($cashMovement->trashed()) {
            return response()->json([
                'message' => 'El movimiento ya está anulado.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'reason.required' => 'El motivo de anulación es obligatorio.',
            'reason.min'      => 'El motivo debe tener al menos 10 caracteres.',
        ]);

        $closing = null;
        DB::transaction(function () use ($cashMovement, $cashSession, $request, $validated, $closedCorrection, &$closing) {
            $cashMovement->update([
                'voided_by'   => $request->user()->id,
                'void_reason' => trim($validated['reason']),
            ]);
            $cashMovement->delete();

            $typeLabel = $cashMovement->type === 'expense' ? 'egreso' : 'ingreso';
            $methodLabel = CashMovement::methodText($cashMovement->payment_method, (float) $cashMovement->amount, $cashMovement->cash_amount !== null ? (float) $cashMovement->cash_amount : null);
            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'cash_movement_voided',
                'module'      => 'CashMovement',
                'description' => "Caja #{$cashSession->id}, {$typeLabel} #{$cashMovement->id} de Bs " . number_format((float) $cashMovement->amount, 2, '.', '') . " ({$methodLabel}) anulado por {$request->user()->name}. Motivo: " . trim($validated['reason']),
            ]);

            if ($closedCorrection) {
                $closing = $this->recloseWithSameCount(
                    $cashSession,
                    $request,
                    "{$typeLabel} #{$cashMovement->id} de Bs " . number_format((float) $cashMovement->amount, 2, '.', '') . " ({$methodLabel}) anulado",
                    trim($validated['reason'])
                );
            }
        });

        $stats = $this->calculateSessionStats($cashSession);

        return response()->json([
            'message'  => $closing
                ? 'Movimiento anulado y cierre de caja rehecho con el mismo conteo.'
                : 'Movimiento de caja anulado correctamente.',
            'movement' => $cashMovement->fresh(['user:id,name', 'expenseCategory:id,name,section,group_name', 'editedBy:id,name', 'voidedBy:id,name']),
            'stats'    => $stats,
            'closing'  => $closing,
        ]);
    }

    /**
     * Reglas del gasto dividido (docs/PLAN_GASTO_PAGO_DIVIDIDO.md): solo un gasto se divide, y la parte en efectivo
     * tiene que ser mayor que 0 y menor que el total (el resto va por QR o banco). Devuelve el error o null.
     */
    private function splitError(string $type, string $method, float $amount, ?float $cashAmount): ?string
    {
        if ($method !== 'mixed') {
            return null;
        }
        if ($type !== 'expense') {
            return 'Solo un gasto se puede dividir entre efectivo y QR.';
        }
        if ($cashAmount === null || $cashAmount < 0.01 || $cashAmount > round($amount - 0.01, 2)) {
            return 'La parte en efectivo tiene que ser mayor que 0 y menor que el total del gasto.';
        }

        return null;
    }

    /**
     * ¿Se puede corregir un movimiento de esta caja? Abierta (o reabierta): sí. Cerrada: solo con el permiso por persona
     * «Corregir cajas cerradas». Devuelve la respuesta de error, o null si se puede seguir.
     */
    private function closedCorrectionDenied(Request $request, CashRegisterSession $cashSession): ?JsonResponse
    {
        if ($cashSession->status === 'open') {
            return null;
        }
        if ($cashSession->status !== 'closed') {
            return response()->json(['message' => 'Esta caja no admite correcciones.'], 422);
        }
        if (!$this->permissionService->can($request->user(), 'register_corrections', 'edit')) {
            return response()->json([
                'message' => 'Esta caja está cerrada. Corregirla requiere el permiso «Corregir cajas cerradas».',
            ], 403);
        }

        return null;
    }

    /**
     * Corrección de un movimiento en una caja cerrada (docs/PLAN_CORREGIR_GASTOS_CAJA.md, §3.8): es el mismo «reabrir y
     * volver a cerrar» de la Fase 4, hecho en un solo paso y sin recontar el efectivo.
     *
     * - Guarda el cierre anterior en el historial (cash_session_closings), reabierto y vuelto a cerrar con el mismo motivo.
     * - Recalcula el teórico y la diferencia con el mismo efectivo contado.
     * - Conserva la fecha del cierre, el contado, el desglose, el retiro y el fondo para mañana (la caja siguiente no cambia).
     * - La nota automática de faltante se rehace; una nota escrita por el cajero se conserva.
     *
     * @return array{expected_before: float, expected_after: float, actual: float, difference_before: float, difference_after: float}
     */
    private function recloseWithSameCount(CashRegisterSession $session, Request $request, string $what, string $reason): array
    {
        $user = $request->user();
        $fullReason = "Corrección sin recontar ({$what}). Motivo: {$reason}";
        $before = [
            'expected'   => (float) $session->expected_closing,
            'difference' => (float) $session->difference,
        ];

        CashSessionClosing::create([
            'cash_register_session_id' => $session->id,
            'expected_closing'         => $session->expected_closing,
            'actual_closing'           => $session->actual_closing,
            'difference'               => $session->difference,
            'total_expenses'           => $session->total_expenses,
            'cash_withdrawn'           => $session->cash_withdrawn,
            'cash_left_for_next'       => $session->cash_left_for_next,
            'bill_breakdown'           => $session->bill_breakdown,
            'diff_note'                => $session->diff_note,
            'closed_at'                => $session->closed_at,
            'reopened_by'              => $user->id,
            'reopened_at'              => now(),
            'reopen_reason'            => $fullReason,
            'reclosed_by'              => $user->id,
            'reclosed_at'              => now(),
            'reclose_reason'           => $fullReason,
        ]);

        $stats = $this->calculateSessionStats($session);
        $expected = round((float) $stats['expected_cash'], 2);
        $difference = round((float) $session->actual_closing - $expected, 2);

        // La nota que pone el sistema («Faltante de Bs X registrado en cierre.») se rehace; la del cajero se conserva
        $note = $session->diff_note;
        if ($note === null || preg_match('/^Faltante de Bs [\d.,]+ registrado en cierre\.$/u', trim($note))) {
            $note = $difference < 0 ? 'Faltante de Bs ' . number_format(abs($difference), 2) . ' registrado en cierre.' : null;
        }

        $session->update([
            'expected_closing' => $expected,
            'difference'       => $difference,
            'total_expenses'   => $stats['total_expenses'],
            'diff_note'        => $note,
        ]);

        AuditLogger::log(
            'cash_session_reclosed',
            'Sales',
            sprintf(
                'Caja #%d del %s corregida sin recontar por %s (%s). Teórico Bs %s → Bs %s; contado Bs %s (sin cambio); diferencia Bs %s → Bs %s. Motivo: %s',
                $session->id,
                $session->opened_at->format('d/m/Y'),
                $user->name,
                $what,
                number_format($before['expected'], 2),
                number_format($expected, 2),
                number_format((float) $session->actual_closing, 2),
                number_format($before['difference'], 2),
                number_format($difference, 2),
                $reason
            ),
            $user->id
        );

        return [
            'expected_before'   => $before['expected'],
            'expected_after'    => $expected,
            'actual'            => (float) $session->actual_closing,
            'difference_before' => $before['difference'],
            'difference_after'  => $difference,
        ];
    }

    /**
     * Valida y resuelve la fecha/hora de un movimiento para que pertenezca al día de la caja y no sea futura.
     */
    private function resolveOccurredAt(CashRegisterSession $cashSession, ?string $occurredAtInput): Carbon
    {
        $dayStart = $cashSession->opened_at->copy()->startOfDay();
        $dayEnd   = $cashSession->opened_at->copy()->endOfDay();

        if (!empty($occurredAtInput)) {
            $occurredAt = Carbon::parse($occurredAtInput)->setTimezone(config('app.timezone'));
            if ($occurredAt->lt($dayStart) || $occurredAt->gt($dayEnd) || $occurredAt->isFuture()) {
                abort(response()->json([
                    'message' => 'La fecha del movimiento debe ser del día de esta caja (' . $dayStart->format('d/m/Y') . ') y no puede ser futura.',
                ], 422));
            }
            return $occurredAt;
        }

        return now()->lte($dayEnd) ? now() : $dayEnd->copy()->startOfMinute();
    }

    /**
     * Listado de movimientos de una sesión de caja (incluye anulados y datos de corrección).
     */
    public function movements(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession, 'read');

        $movements = $cashSession->movements()
            ->withTrashed()
            ->with(['user:id,name', 'expenseCategory:id,name,section,group_name', 'editedBy:id,name', 'voidedBy:id,name'])
            ->latest('occurred_at')
            ->get();

        return response()->json($movements);
    }

    /**
     * Detalle y estadísticas en tiempo real de una sesión de caja específica.
     */
    public function show(Request $request, CashRegisterSession $cashSession): JsonResponse
    {
        $this->ensureCanAccess($request, $cashSession, 'read');
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

        // Si no tiene permiso para ver otras cajas, solo puede consultar sus propias sesiones
        if (!$this->permissionService->can($request->user(), 'register_others', 'read')) {
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
     * Solo el cajero dueño de la sesión o un usuario con permiso sobre cajas ajenas pueden acceder.
     * Para consulta (show, report, movements) se requiere 'read'.
     * Para operaciones de cambio (close, recordMovement) se requiere 'edit'.
     */
    private function ensureCanAccess(Request $request, CashRegisterSession $session, string $level = 'edit'): void
    {
        $user = $request->user();

        if ((int) $session->cashier_id !== (int) $user->id && !app(PermissionService::class)->can($user, 'register_others', $level)) {
            abort(403, 'No tienes permiso para operar sobre la caja de otro usuario.');
        }
    }

    /**
     * Helper para calcular estadísticas en tiempo real de una sesión.
     * Ecuación: expected_cash = opening_amount + cash_sales - cash_expenses + cash_extra_income
     *
     * Las ventas se toman por su vínculo con la sesión (cash_register_session_id),
     * asignado al registrarse cada venta (ver CashSessionResolver).
     *
     * Los pedidos PedidosYa se separan por canal (source), no por método de pago:
     * no se rinden en caja aunque se hayan registrado con otro método.
     */
    private function calculateSessionStats(CashRegisterSession $session): array
    {
        $salesQuery = Sale::where('cash_register_session_id', $session->id);

        $allCompleted   = (clone $salesQuery)->where('status', 'completed')->get();
        $voidedSales    = (clone $salesQuery)->where('status', 'voided')->get();

        [$pedidosYaSales, $completedSales] = $allCompleted->partition(fn ($s) => $s->source === 'pedidosya');

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

        // Un gasto dividido (mixed) resta de la gaveta solo su parte en efectivo; el resto cuenta como QR
        // (docs/PLAN_GASTO_PAGO_DIVIDIDO.md)
        $cashExpenses = round((float) CashMovement::where('cash_register_session_id', $session->id)
            ->where('type', 'expense')
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method IS NULL OR payment_method = 'cash' THEN amount WHEN payment_method = 'mixed' THEN cash_amount ELSE 0 END), 0) AS total")
            ->value('total'), 2);

        $nonCashExpenses = round((float) CashMovement::where('cash_register_session_id', $session->id)
            ->where('type', 'expense')
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method IN ('qr', 'card') THEN amount WHEN payment_method = 'mixed' THEN amount - cash_amount ELSE 0 END), 0) AS total")
            ->value('total'), 2);

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
        // Descuentos manuales (docs/PLAN_DESCUENTOS_CAJA.md): solo los positivos; un importe negativo es un recargo viejo
        $discounted       = $completedSales->filter(fn ($s) => (float) $s->discount_amount > 0);
        $totalDiscounts   = round((float) $discounted->sum('discount_amount'), 2);

        // Ecuación del arqueo: fondo + ventas efectivo - egresos efectivo + ingresos_extra efectivo
        $expectedCash = round((float) $session->opening_amount + $cashSales - $cashExpenses + $cashExtraIncome, 2);

        return [
            'sales_count'       => $completedSales->count(),
            'voided_count'      => $voidedSales->count(),
            'total_sales'       => $totalSalesAmount,
            'total_discounts'   => $totalDiscounts,
            'discount_count'    => $discounted->count(),
            'courtesy_count'    => $discounted->filter(fn ($s) => (float) $s->total_amount <= 0)->count(),
            'cash_sales'        => $cashSales,
            'qr_sales'          => $qrSales,
            'card_sales'        => $cardSales,
            'other_sales'       => $otherSales,
            'pedidosya_count'   => $pedidosYaSales->count(),
            'pedidosya_total'   => (float) $pedidosYaSales->sum('total_amount'),
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

        // Cargar lista de movimientos para el ticket de cierre (incluye anulados y correcciones)
        $movements = $session->movements()
            ->withTrashed()
            ->with(['user:id,name', 'editedBy:id,name', 'voidedBy:id,name'])
            ->orderBy('occurred_at')
            ->get()
            ->map(fn ($m) => [
                'id'             => $m->id,
                'type'           => $m->type,
                'payment_method' => $m->payment_method ?? 'cash',
                'cash_amount'    => $m->cash_amount !== null ? (float) $m->cash_amount : null,
                'amount'         => (float) $m->amount,
                'description'    => $m->description,
                'user'           => $m->user?->name,
                'created_at'     => $m->created_at?->toIso8601String(),
                'occurred_at'    => $m->occurred_at?->toIso8601String(),
                'is_voided'      => $m->trashed(),
                'voided_by'      => $m->voidedBy?->name,
                'void_reason'    => $m->void_reason,
                'deleted_at'     => $m->deleted_at?->toIso8601String(),
                'is_edited'      => (int) ($m->edit_count ?? 0) > 0,
                'edited_by'      => $m->editedBy?->name,
                'edited_at'      => $m->edited_at?->toIso8601String(),
                'edit_reason'    => $m->edit_reason,
                'edit_previous'  => $m->edit_previous,
                'edit_count'     => (int) ($m->edit_count ?? 0),
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
            // QR anotado de la app del banco al cerrar y su diferencia con el sistema (plan de conciliación, G)
            'qr_bank_reported'  => $session->qr_bank_reported !== null ? (float) $session->qr_bank_reported : null,
            'qr_bank_difference' => $session->qr_bank_reported !== null
                ? round((float) $session->qr_bank_reported - (float) $stats['qr_sales'], 2)
                : null,
            'bill_breakdown'    => $session->bill_breakdown,
            'movements'         => $movements,
            'movement_corrections_count' => collect($movements)->filter(fn ($m) => $m['is_edited'] || $m['is_voided'])->count(),
            'discounts'         => $this->sessionDiscounts($session),
            'summary'           => $stats,
            'recalculated'      => $this->recalculatedClosing($session, $stats),
            'closings'          => $session->closings()->with(['reopenedBy:id,name', 'reclosedBy:id,name'])->get()
                ->map(fn (CashSessionClosing $c) => [
                    'closed_at'          => $c->closed_at?->toIso8601String(),
                    'expected_closing'   => (float) $c->expected_closing,
                    'actual_closing'     => (float) $c->actual_closing,
                    'difference'         => (float) $c->difference,
                    'cash_left_for_next' => $c->cash_left_for_next !== null ? (float) $c->cash_left_for_next : null,
                    'reopened_by'        => $c->reopenedBy?->name,
                    'reopened_at'        => $c->reopened_at?->toIso8601String(),
                    'reopen_reason'      => $c->reopen_reason,
                    'reclosed_by'        => $c->reclosedBy?->name,
                    'reclosed_at'        => $c->reclosed_at?->toIso8601String(),
                    'reclose_reason'     => $c->reclose_reason,
                ])->values()->all(),
            'chain'             => $this->nextSessionChain($session),
        ];
    }

    /**
     * Ventas del local con descuento manual en la caja, para el reporte: quién, cuánto y por qué.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sessionDiscounts(CashRegisterSession $session): array
    {
        return Sale::where('cash_register_session_id', $session->id)
            ->where('status', 'completed')
            ->where('source', '!=', 'pedidosya')
            ->where('discount_amount', '>', 0)
            ->with('cashier:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Sale $s) => [
                'id'              => $s->id,
                'order_number'    => $s->order_number,
                'created_at'      => $s->created_at?->toIso8601String(),
                'cashier'         => $s->cashier?->name,
                'subtotal'        => (float) $s->subtotal,
                'discount_amount' => (float) $s->discount_amount,
                'total_amount'    => (float) $s->total_amount,
                'percent'         => DiscountPolicy::percent((float) $s->discount_amount, (float) $s->subtotal),
                'is_courtesy'     => (float) $s->total_amount <= 0,
                'discount_reason' => $s->discount_reason,
            ])->values()->all();
    }

    /**
     * Caja siguiente: con qué fondo abrió frente a lo que esta caja dejó «para mañana». Si una corrección cambió
     * ese fondo, la caja siguiente no se toca sola: el reporte lo avisa.
     *
     * @return array{next_id: int, next_opened_at: ?string, next_opening_amount: float, left_for_next: float, matches: bool}|null
     */
    private function nextSessionChain(CashRegisterSession $session): ?array
    {
        if ($session->status !== 'closed' || $session->cash_left_for_next === null) {
            return null;
        }
        $next = CashRegisterSession::where('opened_at', '>', $session->opened_at)->orderBy('opened_at')->first();
        if (!$next) {
            return null;
        }
        $left = (float) $session->cash_left_for_next;

        return [
            'next_id'             => $next->id,
            'next_opened_at'      => $next->opened_at?->toIso8601String(),
            'next_opening_amount' => (float) $next->opening_amount,
            'left_for_next'       => $left,
            'matches'             => abs($left - (float) $next->opening_amount) < 0.005,
        ];
    }

    /**
     * Arqueo de una caja cerrada con las cifras de hoy. El cierre guardado (teórico, contado y diferencia) no se
     * toca: es lo que se firmó. Pero si después se editó, anuló o sincronizó tarde una venta, o cambió un egreso,
     * el teórico de hoy ya no es el del cierre y el reporte lo dice, junto con los cambios auditados.
     *
     * @return array{changed: bool, expected_closing: float, difference: float, total_expenses: float, changes: array}|null
     */
    private function recalculatedClosing(CashRegisterSession $session, array $stats): ?array
    {
        if ($session->status !== 'closed' || $session->expected_closing === null) {
            return null;
        }

        $expectedNow = (float) $stats['expected_cash'];
        $changed = abs($expectedNow - (float) $session->expected_closing) > 0.005
            || abs((float) $stats['total_expenses'] - (float) $session->total_expenses) > 0.005;

        // SaleSyncService::auditClosedSessionChange escribe «… afecta la caja #ID, cerrada el …»
        $changes = AuditLog::with('user:id,name')
            ->where('action', 'closed_session_sale_change')
            ->where('description', 'like', "%afecta la caja #{$session->id},%")
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log) => [
                'at'          => $log->created_at?->toIso8601String(),
                'user'        => $log->user?->name,
                'description' => strstr($log->description, ' afecta la caja', true) ?: $log->description,
            ])->values()->all();

        return [
            'changed'          => $changed,
            'expected_closing' => $expectedNow,
            'difference'       => round((float) $session->actual_closing - $expectedNow, 2),
            'total_expenses'   => (float) $stats['total_expenses'],
            'changes'          => $changes,
        ];
    }
}
