<?php

namespace Tests\Feature;

use App\Models\PedidosYaOrder;
use App\Models\PedidosYaSettlement;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reglas de las semanas PedidosYa (auditoría de Finanzas M1–M3): rango del CSV, solapes,
 * reimportación, conciliación completa, prorrateo entre meses y ventas del POS sin conciliar.
 */
class PedidosYaSettlementRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private string $csv;

    /** Montos del estado de cuenta de la semana 14–20/09 */
    private array $statement = [
        'payout_date'       => '2026-09-24',
        'net_sales'         => 710.00,
        'delivery_fees'     => 0.00,
        'service_fees'      => 187.00,
        'operating_charges' => 0.00,
        'advertising'       => 112.65,
        'refunds'           => 153.04,
        'taxes'             => 39.83,
        'total_payout'      => 523.56,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 7, 12, 0, 0, 'America/La_Paz'));
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->csv = base_path('tests/Fixtures/semana_pedidosya_anonimizada.csv');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function import(array $overrides = [])
    {
        $payload = array_merge($this->statement, [
            'csv_file' => new UploadedFile($this->csv, 'semana.csv', 'text/csv', null, true),
        ], $overrides);

        return $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements', $payload, ['Accept' => 'application/json']);
    }

    private function pnl(int $year, int $month): array
    {
        return $this->actingAs($this->owner)->getJson("/api/finance/pnl?year={$year}&month={$month}")->assertOk()->json();
    }

    public function test_preview_suggests_the_week_from_the_csv(): void
    {
        $res = $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements/preview', [
            'csv_file' => new UploadedFile($this->csv, 'semana.csv', 'text/csv', null, true),
        ], ['Accept' => 'application/json'])->assertOk();

        // Pedidos del 15 al 20/09 → semana de lunes 14 a domingo 20
        $res->assertJsonPath('start_date', '2026-09-14')
            ->assertJsonPath('end_date', '2026-09-20')
            ->assertJsonPath('first_order_date', '2026-09-15')
            ->assertJsonPath('last_order_date', '2026-09-20')
            ->assertJsonPath('orders_outside_range', [])
            ->assertJsonPath('overlapping', []);
        $this->assertEquals(40.0, $res->json('csv_pedidosya_discount'));
        $this->assertDatabaseCount('pedidosya_settlements', 0);
    }

    public function test_import_without_dates_takes_the_week_from_the_csv_and_reconciles(): void
    {
        $res = $this->import()->assertCreated();

        $res->assertJsonPath('settlement.start_date', '2026-09-14')
            ->assertJsonPath('settlement.end_date', '2026-09-20')
            ->assertJsonPath('warnings', [])
            ->assertJsonPath('settlement.reconciliation.status', 'ok');

        $checks = collect($res->json('settlement.reconciliation.checks'))->keyBy('key');
        $this->assertTrue($checks['payout']['ok']);
        $this->assertTrue($checks['commission']['ok']);
        // 748 − 40 (descuento PedidosYa del CSV) = 708 frente a 710 del estado de cuenta: informativa
        $this->assertEquals(708.0, $checks['net_sales']['expected']);
        $this->assertEquals(2.0, $checks['net_sales']['difference']);
        $this->assertTrue($checks['net_sales']['informative']);
    }

    public function test_overlapping_weeks_are_rejected(): void
    {
        $this->import(['start_date' => '2026-09-14', 'end_date' => '2026-09-20'])->assertCreated();

        $this->import(['start_date' => '2026-09-15', 'end_date' => '2026-09-21'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_date');

        $this->assertDatabaseCount('pedidosya_settlements', 1);
        $this->assertEquals(710.0, $this->pnl(2026, 9)['revenue']['pedidosya_net']);
    }

    public function test_reimporting_the_same_week_replaces_it(): void
    {
        $first = $this->import()->assertCreated()->json('settlement.id');
        $second = $this->import(['advertising' => 100.00, 'total_payout' => 536.21])->assertCreated()->json('settlement.id');

        $this->assertEquals($first, $second);
        $this->assertDatabaseCount('pedidosya_settlements', 1);
        $this->assertEquals(13, PedidosYaOrder::where('settlement_id', $first)->count());
        $this->assertEquals(100.0, (float) PedidosYaSettlement::find($first)->advertising);
    }

    public function test_orders_that_belong_to_another_week_are_rejected(): void
    {
        $this->import()->assertCreated();

        // Misma planilla con otra semana (sin solape de fechas): sus pedidos ya están en 14–20/09
        $this->import(['start_date' => '2026-09-21', 'end_date' => '2026-09-27'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('csv_file');

        $this->assertDatabaseCount('pedidosya_settlements', 1);
    }

    public function test_orders_outside_the_week_are_reported(): void
    {
        $res = $this->import(['start_date' => '2026-09-16', 'end_date' => '2026-09-22'])->assertCreated();

        $warnings = $res->json('warnings');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('1 pedido(s)', $warnings[0]);
        $this->assertStringContainsString('2284401825', $warnings[0]);
    }

    public function test_commission_mismatch_is_a_reconciliation_difference(): void
    {
        // Servicios 180 frente a 187 del CSV; el total liquidado se ajusta para que la fórmula cuadre
        $res = $this->import(['service_fees' => 180.00, 'total_payout' => 530.56])->assertCreated();

        $res->assertJsonPath('settlement.reconciliation.status', 'difference');
        $commission = collect($res->json('settlement.reconciliation.checks'))->firstWhere('key', 'commission');
        $this->assertFalse($commission['ok']);
        $this->assertEquals(-7.0, $commission['difference']);

        $warning = collect($this->pnl(2026, 9)['warnings'])->firstWhere('code', 'pedidosya_reconciliation');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('14/09–20/09', $warning['message']);
    }

    public function test_settlement_can_be_deleted_and_imported_again(): void
    {
        $id = $this->import()->assertCreated()->json('settlement.id');

        $this->actingAs($this->owner)->getJson("/api/finance/pedidosya-settlements/{$id}")
            ->assertOk()->assertJsonPath('settlement.id', $id);

        $this->actingAs($this->owner)->deleteJson("/api/finance/pedidosya-settlements/{$id}")->assertOk();
        $this->assertSoftDeleted('pedidosya_settlements', ['id' => $id]);
        $this->assertEquals(0.0, $this->pnl(2026, 9)['revenue']['pedidosya_net']);

        $newId = $this->import()->assertCreated()->json('settlement.id');
        $this->assertNotEquals($id, $newId);
        $this->assertEquals(13, PedidosYaOrder::where('settlement_id', $newId)->count());
    }

    public function test_a_week_crossing_two_months_is_prorated_by_order_date(): void
    {
        $st = PedidosYaSettlement::create([
            'start_date'        => '2026-09-28',
            'end_date'          => '2026-10-04',
            'net_sales'         => 100.01,
            'service_fees'      => 25.00,
            'advertising'       => 10.00,
            'refunds'           => 0,
            'taxes'             => 0,
            'total_payout'      => 65.01,
            'calculated_payout' => 65.01,
            'discrepancy'       => 0,
            'orders_count'      => 3,
            'delivered_count'   => 2,
            'cancelled_count'   => 1,
            'csv_gross_sales'   => 100.00,
            'csv_commission'    => 25.00,
            'user_id'           => $this->owner->id,
        ]);

        foreach ([
            ['A1', 'Entregado', '2026-09-29 20:00:00', 60],
            ['A2', 'Entregado', '2026-10-02 19:00:00', 40],
            ['A3', 'Cancelado', '2026-10-03 18:00:00', 30],
        ] as [$number, $status, $date, $subtotal]) {
            PedidosYaOrder::create([
                'settlement_id' => $st->id,
                'order_number'  => $number,
                'status'        => $status,
                'order_date'    => $date,
                'subtotal'      => $subtotal,
            ]);
        }

        $sep = $this->pnl(2026, 9);
        $oct = $this->pnl(2026, 10);

        // 60 % de las ventas entregadas en septiembre, 40 % en octubre
        $this->assertEquals(60.01, $sep['revenue']['pedidosya_net']);
        $this->assertEquals(40.00, $oct['revenue']['pedidosya_net']);
        $this->assertEquals(100.01, round($sep['revenue']['pedidosya_net'] + $oct['revenue']['pedidosya_net'], 2));

        $this->assertEquals(21.00, $sep['operating_expenses']['total']); // 15 comisión + 6 publicidad
        $this->assertEquals(14.00, $oct['operating_expenses']['total']); // 10 + 4

        $this->assertEquals(60.0, $sep['pedidosya_analysis']['gross_sales']);
        $this->assertEquals(1, $sep['pedidosya_analysis']['delivered_count']);
        $this->assertEquals(0, $sep['pedidosya_analysis']['cancelled_count']);
        $this->assertEquals(1, $oct['pedidosya_analysis']['cancelled_count']);
        $this->assertTrue($sep['pedidosya_analysis']['prorated']);

        // La semana aparece en la pestaña de ambos meses
        $this->assertCount(1, $this->actingAs($this->owner)->getJson('/api/finance/pedidosya-settlements?year=2026&month=9')->json('data'));
        $this->assertCount(1, $this->actingAs($this->owner)->getJson('/api/finance/pedidosya-settlements?year=2026&month=10')->json('data'));
    }

    public function test_pos_pedidosya_sales_count_only_on_days_without_an_imported_week(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->import()->assertCreated();

        foreach ([['2026-09-17 20:00:00', 38], ['2026-09-23 19:00:00', 56]] as $i => [$at, $amount]) {
            Sale::create([
                'id'           => Str::uuid()->toString(),
                'order_number' => 'PYA-' . $i,
                'cashier_id'   => $cashier->id,
                'subtotal'     => $amount,
                'total_amount' => $amount,
                'status'       => 'completed',
                'source'       => 'pedidosya',
                'created_at'   => $at,
                'updated_at'   => $at,
            ]);
        }

        $data = $this->pnl(2026, 9);

        // La del 17/09 ya está en la liquidación; la del 23/09 se suma mientras tanto
        $this->assertEquals(56.0, $data['revenue']['pedidosya_pos_unreconciled']);
        $this->assertEquals(1, $data['revenue']['pedidosya_pos_unreconciled_count']);
        $this->assertEquals(766.0, $data['revenue']['total']);

        $missing = collect($data['warnings'])->firstWhere('code', 'pedidosya_missing_weeks');
        $this->assertEquals(['31/08–06/09', '07/09–13/09', '21/09–27/09', '28/09–04/10'], $missing['weeks']);
        $this->assertStringContainsString('1 venta(s) PedidosYa del POS', $missing['message']);
    }
}
