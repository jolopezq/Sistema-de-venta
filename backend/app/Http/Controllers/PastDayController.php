<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\SystemStartDate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Control diario, ventas de días pasados (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 3): qué pasará con el stock y
 * los puntos de una venta de esa fecha, y la fecha de inicio del sistema.
 */
class PastDayController extends Controller
{
    public function __construct(
        private readonly SystemStartDate $startDate,
        private readonly InventoryService $inventory,
    ) {
    }

    /**
     * GET /admin/sales/day-info?date=AAAA-MM-DD
     * - historical: la venta será histórica (sin stock ni puntos);
     * - settled_count: insumos contados o cargados después de ese día, que no se descuentan.
     */
    public function dayInfo(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $day = Carbon::parse($validated['date'], config('app.timezone'))->startOfDay();
        $info = $this->startDate->info();
        $historical = $this->startDate->isHistorical($day);

        return response()->json([
            'date'              => $day->toDateString(),
            'system_start_date' => $info['date'],
            'start_configured'  => $info['configured'],
            'historical'        => $historical,
            'settled_count'     => $historical ? 0 : count($this->inventory->settledAfter($day->copy()->endOfDay())),
        ]);
    }

    /** PUT /admin/settings/system-start-date {date: AAAA-MM-DD | null} (solo Super Admin). */
    public function setStartDate(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $before = $this->startDate->info()['date'];
        $this->startDate->set($validated['date'] ?? null);
        $after = $this->startDate->info();

        AuditLogger::log(
            'system_start_date_changed',
            'Sales',
            "Inicio del sistema: {$before} → {$after['date']}" . ($after['configured'] ? '' : ' (primera venta del POS)') . ", por {$request->user()->name}.",
            $request->user()->id
        );

        return response()->json(['system_start_date' => $after['date'], 'start_configured' => $after['configured']]);
    }
}
