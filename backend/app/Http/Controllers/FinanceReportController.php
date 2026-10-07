<?php

namespace App\Http\Controllers;

use App\Services\FinanceReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceReportController extends Controller
{
    public function __construct(
        protected FinanceReportService $reportService
    ) {}

    public function pnl(Request $request): JsonResponse
    {
        $now = Carbon::now(config('app.timezone', 'America/La_Paz'));
        $year = (int) $request->input('year', $now->year);
        $month = (int) $request->input('month', $now->month);

        $report = $this->reportService->getMonthlyPnL($year, $month);

        return response()->json($report);
    }
}
