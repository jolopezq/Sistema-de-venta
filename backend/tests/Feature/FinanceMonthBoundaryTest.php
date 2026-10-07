<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use App\Services\FinanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las ventas se guardan en hora de La Paz: el mes del Estado de Resultados debe cortar a las
 * 00:00 locales, no a las 00:00 UTC.
 */
class FinanceMonthBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_uses_la_paz_boundaries(): void
    {
        $this->assertSame('America/La_Paz', config('app.timezone'));

        $this->sale('2026-08-31 23:30:00', 1);   // agosto
        $this->sale('2026-09-01 01:00:00', 7);   // septiembre
        $this->sale('2026-09-30 22:30:00', 100); // septiembre
        $this->sale('2026-10-01 02:00:00', 50);  // octubre

        $report = app(FinanceReportService::class);

        $this->assertEquals(107, $report->getMonthlyPnL(2026, 9)['revenue']['local_sales']);
        $this->assertEquals(50, $report->getMonthlyPnL(2026, 10)['revenue']['local_sales']);
        $this->assertEquals(1, $report->getMonthlyPnL(2026, 8)['revenue']['local_sales']);
    }

    private function sale(string $createdAt, float $total): void
    {
        $user = User::first() ?? User::factory()->create();
        Sale::create([
            'id' => (string) Str::uuid(), 'order_number' => 'T-' . Str::random(6), 'cashier_id' => $user->id,
            'subtotal' => $total, 'total_amount' => $total, 'status' => 'completed', 'source' => 'pos',
            'created_at' => $createdAt,
        ]);
    }
}
