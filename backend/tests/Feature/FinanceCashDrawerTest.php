<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Finanzas: efectivo que debía llegar a la mano, faltantes de caja y avisos de lo que falta cargar
 * (docs/PLAN_CONCILIACION_CAJA.md, A, B y F).
 */
class FinanceCashDrawerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(10, 0));
        $this->owner = User::factory()->create(['role' => 'super_admin', 'name' => 'Dueño']);
        $this->cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Lourdes']);
    }

    /** Caja del día; cerrada si trae [contado, retirado, queda]. */
    private function caja(string $day, float $opening, ?array $close = null): CashRegisterSession
    {
        return CashRegisterSession::create([
            'cashier_id'         => $this->cashier->id,
            'opening_amount'     => $opening,
            'status'             => $close ? 'closed' : 'open',
            'opened_at'          => "{$day} 15:00:00",
            'closed_at'          => $close ? "{$day} 22:00:00" : null,
            'actual_closing'     => $close[0] ?? null,
            'expected_closing'   => $close[0] ?? null,
            'difference'         => 0,
            'cash_withdrawn'     => $close[1] ?? null,
            'cash_left_for_next' => $close[2] ?? null,
        ]);
    }

    private function sale(?CashRegisterSession $session, string $at, float $total, string $method, string $source = 'pos'): Sale
    {
        $sale = Sale::create([
            'id' => (string) Str::uuid(), 'cashier_id' => $this->cashier->id, 'subtotal' => $total, 'total_amount' => $total,
            'status' => 'completed', 'source' => $source, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('sales')->where('id', $sale->id)->update(['cash_register_session_id' => $session?->id]);
        SalePayment::create(['sale_id' => $sale->id, 'method' => $method, 'amount' => $total]);

        return $sale;
    }

    private function movement(CashRegisterSession $session, string $type, float $amount, ?string $method = 'cash', ?float $cash = null, ?int $category = null, ?string $at = null): CashMovement
    {
        return CashMovement::create([
            'cash_register_session_id' => $session->id, 'user_id' => $this->owner->id, 'type' => $type, 'amount' => $amount,
            'payment_method' => $method, 'cash_amount' => $cash, 'expense_category_id' => $category,
            'description' => 'Prueba', 'occurred_at' => $at ?? $session->opened_at->copy()->setTime(18, 0),
        ]);
    }

    private function pnl(int $month = 10): array
    {
        return $this->actingAs($this->owner)->getJson("/api/finance/pnl?year=2026&month={$month}")->assertOk()->json();
    }

    private function codes(array $pnl): array
    {
        return array_column($pnl['warnings'], 'code');
    }

    public function test_lo_que_debia_llegar_a_la_mano_cuadra_con_lo_retirado_de_las_cajas(): void
    {
        $fruit = ExpenseCategory::where('name', 'Ingredientes y frutas')->value('id');

        // 01/10: fondo 242, vende 110 en efectivo y 82 por QR; cuenta 352, retira 100 y deja 252
        $d1 = $this->caja('2026-10-01', 242, [352, 100, 252]);
        $this->sale($d1, '2026-10-01 18:00:00', 110, 'cash');
        $this->sale($d1, '2026-10-01 18:10:00', 82, 'qr');

        // 02/10: abre con 0 y carga el fondo como ingreso extra (como pasó en octubre); fruta 74 en efectivo y un
        // gasto dividido de 100 (50 en efectivo); cuenta 260 con 2 de faltante (esperado 262), retira 10 y deja 250
        $d2 = $this->caja('2026-10-02', 0, [260, 10, 250]);
        $this->movement($d2, 'extra_income', 252);
        $this->sale($d2, '2026-10-02 18:00:00', 134, 'cash');
        $this->movement($d2, 'expense', 74, 'cash', null, $fruit);
        $this->movement($d2, 'expense', 100, 'mixed', 50, $fruit);
        $this->movement($d2, 'expense', 40, 'qr', null, $fruit);

        // 03/10: abre con 254 (la anterior dejó 250: salto de +4)
        $d3 = $this->caja('2026-10-03', 254, [300, 46, 254]);
        $this->sale($d3, '2026-10-03 18:00:00', 46, 'cash');

        // 04/10: caja que quedó abierta con una venta en efectivo; y una venta en efectivo sin caja
        $open = $this->caja('2026-10-04', 254);
        $this->sale($open, '2026-10-04 18:00:00', 30, 'cash');
        $this->sale(null, '2026-10-05 03:00:00', 20, 'cash');

        $drawer = $this->pnl()['cash_in']['drawer'];

        $this->assertEquals(110 + 134 + 46 + 30 + 20, $drawer['collected']);
        $this->assertEquals(30, $drawer['in_open_sessions']);
        $this->assertEquals(20, $drawer['outside_sessions']);
        $this->assertEquals(74 + 50, $drawer['drawer_expenses'], 'Del gasto dividido solo cuenta la parte en efectivo');
        $this->assertEquals(252, $drawer['extra_income']);
        $this->assertEquals(-2, $drawer['differences']);
        $this->assertEquals(242, $drawer['fund_start']);
        $this->assertEquals(254, $drawer['fund_end']);
        $this->assertEquals(-252 + 4, $drawer['fund_jumps']);
        $this->assertEquals(156, $drawer['withdrawn']);
        $this->assertEquals(0, $drawer['unexplained'], 'La cuenta llega exactamente a lo retirado');
        $this->assertSame([$open->id], array_column($drawer['open_sessions'], 'session_id'));
        $this->assertSame([['session_id' => $d2->id, 'date' => '2026-10-02', 'cashier' => 'Lourdes', 'amount' => -2]], $drawer['difference_items']);
    }

    public function test_el_faltante_de_un_cierre_aparece_en_el_estado_de_resultados(): void
    {
        $short = $this->caja('2026-10-06', 100, [140, 40, 100]);   // esperado 150: faltan 10
        $this->sale($short, '2026-10-06 18:00:00', 50, 'cash');
        $extra = $this->caja('2026-10-07', 100, [133, 33, 100]);   // esperado 130: sobran 3
        $this->sale($extra, '2026-10-07 18:00:00', 30, 'cash');

        $pnl = $this->pnl();
        $items = collect($pnl['operating_expenses']['items'])->where('source', 'cash_difference')->values();

        $this->assertCount(2, $items);
        $this->assertEquals(10, $items[0]['amount'], 'Un faltante suma como gasto');
        $this->assertEquals(-3, $items[1]['amount'], 'Un sobrante lo reduce');
        $this->assertSame('Faltantes y sobrantes de caja', $items[0]['category_name']);
        $this->assertStringContainsString('06/10 (Lourdes): faltante de Bs 10,00', $items[0]['description']);
        $this->assertEquals(80 - 7, $pnl['net_profit']['amount']);
    }

    public function test_avisa_ventas_sin_caja_y_cajas_de_dias_pasados_sin_cerrar(): void
    {
        $open = $this->caja('2026-10-14', 285);
        $this->sale($open, '2026-10-14 18:00:00', 30, 'qr');
        $this->sale(null, '2026-10-17 02:57:00', 260, 'qr');

        $pnl = $this->pnl();
        $byCode = collect($pnl['warnings'])->keyBy('code');

        $this->assertSame('daily_control', $byCode['sales_without_session']['action']);
        $this->assertSame('2026-10-17', $byCode['sales_without_session']['date']);
        $this->assertEquals(260, $byCode['sales_without_session']['amount']);
        $this->assertStringContainsString('17/10 #', $byCode['sales_without_session']['message']);
        $this->assertSame('2026-10-14', $byCode['open_sessions']['date']);
        $this->assertStringContainsString('14/10 (Lourdes)', $byCode['open_sessions']['message']);
    }

    public function test_avisa_cuando_el_mes_no_tiene_sueldos_ni_servicios(): void
    {
        $day = $this->caja('2026-10-10', 0, [50, 50, 0]);
        $this->sale($day, '2026-10-10 18:00:00', 50, 'cash');

        $this->assertContains('missing_salaries', $this->codes($this->pnl()));
        $this->assertContains('missing_utilities', $this->codes($this->pnl()));

        // Con el sueldo cargado en Finanzas y la luz pagada desde la caja, ya no avisa
        FinanceExpense::create([
            'date' => '2026-10-31', 'expense_category_id' => ExpenseCategory::where('name', 'Salarios y personal')->value('id'),
            'supplier' => 'Sueldo de octubre', 'amount' => 3300, 'payment_method' => 'cash', 'user_id' => $this->owner->id,
        ]);
        $this->movement($day, 'expense', 180, 'qr', null, ExpenseCategory::where('name', 'Servicios básicos (luz, agua)')->value('id'));
        $this->movement($day, 'expense', 179, 'qr', null, ExpenseCategory::where('name', 'Internet y teléfono')->value('id'));

        $codes = $this->codes($this->pnl());
        $this->assertNotContains('missing_salaries', $codes);
        $this->assertNotContains('missing_utilities', $codes);
    }

    public function test_al_principio_del_mes_en_curso_no_avisa_sueldos_ni_servicios(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(10, 0));
        $day = $this->caja('2026-11-05', 0, [50, 50, 0]);
        $this->sale($day, '2026-11-05 18:00:00', 50, 'cash');

        $codes = $this->codes($this->pnl(11));
        $this->assertNotContains('missing_salaries', $codes);
        $this->assertNotContains('missing_utilities', $codes);
    }

    public function test_el_detalle_muestra_el_dia_del_egreso_y_no_el_de_carga(): void
    {
        $fruit = ExpenseCategory::where('name', 'Ingredientes y frutas')->value('id');
        $day = $this->caja('2026-10-08', 0, [0, 0, 0]);
        // Egreso del 08/10 cargado el 02/11
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(22, 41));
        $this->movement($day, 'expense', 85, 'qr', null, $fruit, '2026-10-08 23:59:00');
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(10, 0));

        $item = collect($this->pnl()['cost_of_sales']['items'])->firstWhere('source', 'cash_movement');
        $this->assertSame('2026-10-08', $item['date']);
    }
}
