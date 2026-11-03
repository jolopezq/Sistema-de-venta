<?php

namespace Tests\Feature;

use App\Models\PedidosYaSettlement;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Finanzas: cómo se cobraron las ventas del local (Estado de Resultados) y el dinero que entró en el mes
 * (Resumen), por fecha de cobro.
 */
class FinancePaymentsBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(10, 0));
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
    }

    /** Venta con la hora de La Paz y sus pagos [[método, monto], …]. */
    private function sale(string $at, float $total, array $payments, string $status = 'completed', ?string $source = 'pos'): Sale
    {
        $sale = Sale::create([
            'id' => (string) Str::uuid(), 'cashier_id' => $this->cashier->id, 'subtotal' => $total, 'total_amount' => $total,
            'status' => $status, 'source' => $source, 'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach ($payments as [$method, $amount]) {
            SalePayment::create(['sale_id' => $sale->id, 'method' => $method, 'amount' => $amount]);
        }

        return $sale;
    }

    private function week(string $start, string $end, ?string $payout, float $total): PedidosYaSettlement
    {
        return PedidosYaSettlement::create([
            'start_date' => $start, 'end_date' => $end, 'payout_date' => $payout,
            'net_sales' => $total, 'service_fees' => 0, 'total_payout' => $total, 'calculated_payout' => $total,
            'user_id' => $this->owner->id,
        ]);
    }

    private function pnl(): array
    {
        return $this->actingAs($this->owner)->getJson('/api/finance/pnl?year=2026&month=10')->assertOk()->json();
    }

    private function seedSales(): void
    {
        $this->sale('2026-10-05 12:00:00', 100, [['cash', 100]]);
        $this->sale('2026-10-06 12:00:00', 150, [['cash', 50], ['qr', 100]]);   // cobro mixto
        $this->sale('2026-10-07 12:00:00', 80, [['card', 80]]);
        $this->sale('2026-10-14 12:00:00', 35, []);                              // sin pago registrado
        $this->sale('2026-10-22 12:00:00', 60, [['qr', 160]]);                   // cobrada de más con QR
        $this->sale('2026-10-23 12:00:00', 0, [['cash', 0]]);                    // cortesía
        $this->sale('2026-10-31 23:30:00', 40, [['cash', 40]]);                  // último día del mes, de noche
        $this->sale('2026-10-08 12:00:00', 500, [['cash', 500]], 'voided');      // anulada: fuera
        $this->sale('2026-10-09 12:00:00', 70, [['delivery_platform', 70]], 'completed', 'pedidosya'); // PedidosYa: fuera
        $this->sale('2026-11-01 00:10:00', 999, [['cash', 999]]);                // otro mes
        $this->sale('2026-09-30 23:59:00', 999, [['cash', 999]]);                // otro mes
    }

    public function test_local_sales_break_down_by_payment_method_and_add_up_exactly(): void
    {
        $this->seedSales();
        $rev = $this->pnl()['revenue'];

        $this->assertEqualsWithDelta(465.0, $rev['local_sales'], 0.001);
        $this->assertSame(7, $rev['local_sales_count']);
        $this->assertEqualsWithDelta(190.0, $rev['cash_sales'], 0.001);  // 100 + 50 + 0 + 40
        $this->assertEqualsWithDelta(260.0, $rev['qr_sales'], 0.001);    // 100 + 160
        $this->assertEqualsWithDelta(80.0, $rev['card_sales'], 0.001);
        $this->assertEqualsWithDelta(0.0, $rev['other_sales'], 0.001);

        // Sin pago (+35) y cobrada de más (−100): no se reparten a ningún medio
        $this->assertEqualsWithDelta(-65.0, $rev['payments_gap'], 0.001);
        $this->assertSame(2, $rev['payments_gap_count']);
        $this->assertSame(['2026-10-14', '2026-10-22'], array_column($rev['payments_gap_sales'], 'date'));
        $this->assertEqualsWithDelta(0.0, $rev['payments_gap_sales'][0]['paid'], 0.001);
        $this->assertEqualsWithDelta(160.0, $rev['payments_gap_sales'][1]['paid'], 0.001);

        // La suma de los medios más lo sin cuadrar es exactamente lo vendido en el local
        $sum = $rev['cash_sales'] + $rev['qr_sales'] + $rev['card_sales'] + $rev['other_sales'] + $rev['payments_gap'];
        $this->assertEqualsWithDelta($rev['local_sales'], $sum, 0.001);
    }

    public function test_a_month_without_gaps_reports_zero(): void
    {
        $this->sale('2026-10-05 12:00:00', 100, [['cash', 70], ['card', 30]]);
        $rev = $this->pnl()['revenue'];

        $this->assertEqualsWithDelta(0.0, $rev['payments_gap'], 0.001);
        $this->assertSame(0, $rev['payments_gap_count']);
        $this->assertSame([], $rev['payments_gap_sales']);
    }

    public function test_cash_in_counts_pedidosya_by_payout_date(): void
    {
        $this->seedSales();
        $this->week('2026-09-21', '2026-09-27', '2026-10-01', 475.54);  // semana de septiembre pagada en octubre
        $this->week('2026-09-28', '2026-10-04', '2026-10-08', 510.63);
        $this->week('2026-10-05', '2026-10-11', '2026-10-15', 338.64);
        $this->week('2026-10-12', '2026-10-18', null, 746.54);          // cargada sin fecha de pago
        $this->week('2026-10-26', '2026-11-01', '2026-11-05', 600.00);  // se deposita en noviembre

        $in = $this->pnl()['cash_in'];

        $this->assertEqualsWithDelta(190.0, $in['cash'], 0.001);
        $this->assertEqualsWithDelta(260.0, $in['qr'], 0.001);
        $this->assertEqualsWithDelta(80.0, $in['card'], 0.001);
        $this->assertEqualsWithDelta(1324.81, $in['pedidosya_deposited'], 0.001);
        $this->assertSame(['2026-10-01', '2026-10-08', '2026-10-15'], array_column($in['pedidosya_deposits'], 'payout_date'));
        $this->assertEqualsWithDelta(600.0, $in['pedidosya_paid_later'], 0.001);
        $this->assertSame(['2026-10-26'], array_column($in['pedidosya_paid_later_weeks'], 'start_date'));
        $this->assertSame(['2026-10-12'], array_column($in['pedidosya_without_payout_date'], 'start_date'));
        // Sin semana importada: del 19 al 25 de octubre
        $this->assertSame([['from' => '2026-10-19', 'to' => '2026-10-25']], $in['pedidosya_uncovered_ranges']);

        $this->assertEqualsWithDelta(1664.81, $in['to_bank'], 0.001);  // QR + tarjeta + PedidosYa depositado
        $this->assertEqualsWithDelta(1854.81, $in['total'], 0.001);    // + efectivo
    }

    public function test_uncovered_days_stop_today_in_the_current_month(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 10)->setTime(18, 0));
        $this->week('2026-09-28', '2026-10-04', '2026-10-08', 100);

        $in = $this->pnl()['cash_in'];

        $this->assertSame([['from' => '2026-10-05', 'to' => '2026-10-10']], $in['pedidosya_uncovered_ranges']);
        $this->assertEqualsWithDelta(100.0, $in['pedidosya_deposited'], 0.001);
    }
}
