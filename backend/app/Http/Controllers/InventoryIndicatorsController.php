<?php

namespace App\Http\Controllers;

use App\Services\InventoryIndicatorsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Indicadores de inventario del mes (plan de inventario, F5). Solo lectura y solo para el dueño: viven en Finanzas
 * junto al resto del resultado del mes.
 */
class InventoryIndicatorsController extends Controller
{
    public function __construct(protected InventoryIndicatorsService $indicators) {}

    public function index(Request $request): JsonResponse
    {
        $now = Carbon::now(config('app.timezone', 'America/La_Paz'));
        $request->validate([
            'year'  => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json($this->indicators->forMonth(
            (int) $request->input('year', $now->year),
            (int) $request->input('month', $now->month)
        ));
    }
}
