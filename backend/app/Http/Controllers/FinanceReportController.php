<?php

namespace App\Http\Controllers;

use App\Services\BreakEvenService;
use App\Services\FinanceReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceReportController extends Controller
{
    public function __construct(
        protected FinanceReportService $reportService,
        protected BreakEvenService $breakEven,
    ) {}

    /** Margen de contribución y punto de equilibrio del mes. */
    public function breakEven(Request $request): JsonResponse
    {
        $now = Carbon::now(config('app.timezone', 'America/La_Paz'));
        $request->validate([
            'year'  => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json($this->breakEven->forMonth(
            (int) $request->input('year', $now->year),
            (int) $request->input('month', $now->month)
        ));
    }

    public function pnl(Request $request): JsonResponse
    {
        $now = Carbon::now(config('app.timezone', 'America/La_Paz'));
        $year = (int) $request->input('year', $now->year);
        $month = (int) $request->input('month', $now->month);

        $report = $this->reportService->getMonthlyPnL($year, $month);

        return response()->json($report);
    }
}
