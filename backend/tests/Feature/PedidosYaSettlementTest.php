<?php

namespace Tests\Feature;

use App\Models\PedidosYaOrder;
use App\Models\PedidosYaSettlement;
use App\Models\User;
use App\Services\PedidosYaImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PedidosYaSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private string $fixtureCsvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->fixtureCsvPath = base_path('tests/Fixtures/semana_pedidosya_anonimizada.csv');
    }

    public function test_parses_csv_fixture_with_correct_metrics(): void
    {
        $service = app(PedidosYaImportService::class);
        $result = $service->parseCsv($this->fixtureCsvPath);

        // 13 pedidos en el CSV, 11 entregados, 2 cancelados
        $this->assertEquals(13, $result['orders_count']);
        $this->assertEquals(11, $result['delivered_count']);
        $this->assertEquals(2, $result['cancelled_count']);

        // Cifras exactas según §4.2 del Plan
        $this->assertEquals(748.00, $result['csv_gross_sales']);
        $this->assertEquals(187.00, $result['csv_commission']);
        $this->assertEquals(533.06, $result['csv_estimated_income']);
    }

    public function test_imports_settlement_and_reconciles_without_discrepancy(): void
    {
        $file = new UploadedFile(
            $this->fixtureCsvPath,
            'semana.csv',
            'text/csv',
            null,
            true
        );

        $payload = [
            'csv_file'          => $file,
            'start_date'        => '2026-09-14',
            'end_date'          => '2026-09-20',
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

        $response = $this->actingAs($this->superAdmin)
            ->post('/api/finance/pedidosya-settlements', $payload, ['Accept' => 'application/json']);

        $response->assertCreated();
        $settlementId = $response->json('settlement.id');

        $this->assertDatabaseHas('pedidosya_settlements', [
            'id'                => $settlementId,
            'net_sales'         => 710.00,
            'total_payout'      => 523.56,
            'calculated_payout' => 523.56,
            'discrepancy'       => 0.00,
            'delivered_count'   => 11,
            'cancelled_count'   => 2,
        ]);

        $this->assertEquals(13, PedidosYaOrder::where('settlement_id', $settlementId)->count());

        // Reimportar el mismo archivo no duplica los pedidos (idempotencia)
        $file2 = new UploadedFile($this->fixtureCsvPath, 'semana.csv', 'text/csv', null, true);
        $payload['csv_file'] = $file2;
        $response2 = $this->actingAs($this->superAdmin)->post('/api/finance/pedidosya-settlements', $payload, ['Accept' => 'application/json']);
        $response2->assertCreated();
        $this->assertEquals(13, PedidosYaOrder::where('settlement_id', $response2->json('settlement.id'))->count());
    }

    public function test_rejects_csv_missing_required_column(): void
    {
        $invalidCsv = "Nro de pedido,Estado del pedido\n12345,Entregado\n";
        $tempPath = tempnam(sys_get_temp_dir(), 'csv_invalid');
        file_put_contents($tempPath, $invalidCsv);

        $file = new UploadedFile($tempPath, 'invalido.csv', 'text/csv', null, true);

        $payload = [
            'csv_file'     => $file,
            'start_date'   => '2026-09-14',
            'end_date'     => '2026-09-20',
            'net_sales'    => 100,
            'service_fees' => 20,
            'total_payout' => 80,
        ];

        $this->actingAs($this->superAdmin)
            ->post('/api/finance/pedidosya-settlements', $payload, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', "El archivo CSV no contiene la columna obligatoria: 'Fecha del pedido'.");

        $this->assertDatabaseCount('pedidosya_settlements', 0);

        @unlink($tempPath);
    }

    public function test_pnl_includes_pedidosya_revenue_costs_and_retention(): void
    {
        // Crear una liquidación importada en Septiembre
        PedidosYaSettlement::create([
            'start_date'           => '2026-09-14',
            'end_date'             => '2026-09-20',
            'payout_date'          => '2026-09-24',
            'net_sales'            => 710.00,
            'delivery_fees'        => 0.00,
            'service_fees'         => 187.00,
            'operating_charges'    => 0.00,
            'advertising'          => 112.65,
            'refunds'              => 153.04,
            'taxes'                => 39.83,
            'total_payout'         => 523.56,
            'calculated_payout'    => 523.56,
            'discrepancy'          => 0.00,
            'orders_count'         => 13,
            'delivered_count'      => 11,
            'cancelled_count'      => 2,
            'csv_gross_sales'      => 748.00,
            'csv_commission'       => 187.00,
            'csv_estimated_income' => 533.06,
            'user_id'              => $this->superAdmin->id,
        ]);

        $res = $this->actingAs($this->superAdmin)->getJson('/api/finance/pnl?year=2026&month=9');
        $res->assertOk();

        $data = $res->json();

        // Ventas netas PedidosYa (710) sumadas al total de ingresos
        $this->assertEquals(710.00, $data['revenue']['pedidosya_net']);
        $this->assertEquals(710.00, $data['revenue']['total']);

        // Costo de plataforma PedidosYa en gastos operativos:
        // 187 (comisión) + 112.65 (publicidad) + 39.83 (IVA) - 153.04 (reintegros) = 186.44
        $this->assertEquals(186.44, $data['operating_expenses']['total']);

        // Sección específica de rentabilidad de PedidosYa
        $this->assertEquals(748.00, $data['pedidosya_analysis']['gross_sales']);
        $this->assertEquals(523.56, $data['pedidosya_analysis']['total_payout']);
        $this->assertEquals(224.44, $data['pedidosya_analysis']['retention_amount']);
        $this->assertEquals(30.0, $data['pedidosya_analysis']['retention_percent']);
        $this->assertEquals(11, $data['pedidosya_analysis']['delivered_count']);
    }
}
