<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\RecurringExpense;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancePnlCalculationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->cashier    = User::factory()->create(['role' => 'cashier']);
    }

    public function test_monthly_pnl_calculates_exact_revenues_cogs_opex_and_profits(): void
    {
        $timezone = config('app.timezone', 'America/La_Paz');
        $dateInSept = Carbon::create(2026, 9, 15, 14, 0, 0, $timezone);
        $dateUtc = $dateInSept->copy()->setTimezone('UTC');

        // 1. Venta local completada de Septiembre (1000 Bs)
        Sale::create([
            'id'           => Str::uuid()->toString(),
            'order_number' => 'TEST-001',
            'cashier_id'   => $this->cashier->id,
            'subtotal'     => 1000.00,
            'total_amount' => 1000.00,
            'status'       => 'completed',
            'source'       => 'pos',
            'created_at'   => $dateUtc,
            'updated_at'   => $dateUtc,
        ]);

        // 2. Venta PedidosYa del POS (300 Bs) -> NO debe sumarse al ingreso de ventas local
        Sale::create([
            'id'           => Str::uuid()->toString(),
            'order_number' => 'TEST-002',
            'cashier_id'   => $this->cashier->id,
            'subtotal'     => 300.00,
            'total_amount' => 300.00,
            'status'       => 'completed',
            'source'       => 'pedidosya',
            'created_at'   => $dateUtc,
            'updated_at'   => $dateUtc,
        ]);

        // Categorías contables
        $cogsCat1 = ExpenseCategory::create([
            'name'       => 'Helado y açaí',
            'section'    => 'cost_of_sales',
            'group_name' => 'Insumos',
        ]);
        $cogsCat2 = ExpenseCategory::create([
            'name'       => 'Ingredientes y frutas',
            'section'    => 'cost_of_sales',
            'group_name' => 'Insumos',
        ]);
        $opexCat = ExpenseCategory::create([
            'name'       => 'Publicidad y marketing',
            'section'    => 'operating',
            'group_name' => 'Marketing',
        ]);
        $taxCat = ExpenseCategory::create([
            'name'       => 'Régimen simplificado',
            'section'    => 'taxes',
            'group_name' => 'Impuestos',
        ]);

        // 3. Compra pagada fuera de caja (Costo de ventas: 250 Bs)
        FinanceExpense::create([
            'date'                => '2026-09-10',
            'expense_category_id' => $cogsCat1->id,
            'supplier'            => 'Frutas del Valle',
            'amount'              => 250.00,
            'payment_method'      => 'bank_transfer',
            'user_id'             => $this->superAdmin->id,
        ]);

        // 4. Egreso de caja clasificado como costo de ventas (150 Bs)
        $session = CashRegisterSession::create([
            'cashier_id'     => $this->cashier->id,
            'status'         => 'closed',
            'opening_amount' => 200,
            'opened_at'      => $dateUtc,
            'closed_at'      => $dateUtc->copy()->addHours(8),
        ]);

        CashMovement::create([
            'cash_register_session_id' => $session->id,
            'user_id'                  => $this->cashier->id,
            'expense_category_id'      => $cogsCat2->id,
            'type'                     => 'expense',
            'payment_method'           => 'cash',
            'amount'                   => 150.00,
            'description'              => 'Frutilla mercado',
            'created_at'               => $dateUtc,
        ]);

        // 5. Gasto operativo directo (100 Bs)
        FinanceExpense::create([
            'date'                => '2026-09-12',
            'expense_category_id' => $opexCat->id,
            'amount'              => 100.00,
            'payment_method'      => 'qr',
            'user_id'             => $this->superAdmin->id,
        ]);

        // 6. Gastos fijos (desde septiembre). No entran al Estado de Resultados hasta generarse.
        RecurringExpense::create([
            'name'                => 'Alquiler local',
            'expense_category_id' => $opexCat->id,
            'amount'              => 200.00,
            'frequency'           => 'monthly',
            'start_month'         => '2026-09',
            'is_active'           => true,
        ]);

        // 7. Bimestral: corresponde en septiembre, noviembre… y entra completo (sin dividir)
        RecurringExpense::create([
            'name'                => 'Mantenimiento preventivo',
            'expense_category_id' => $opexCat->id,
            'amount'              => 100.00,
            'frequency'           => 'bimonthly',
            'start_month'         => '2026-09',
            'is_active'           => true,
        ]);

        // 8. Impuesto fijo mensual (40 Bs)
        RecurringExpense::create([
            'name'                => 'Cuota Régimen Simplificado',
            'expense_category_id' => $taxCat->id,
            'amount'              => 40.00,
            'frequency'           => 'monthly',
            'start_month'         => '2026-09',
            'is_active'           => true,
        ]);

        // Antes de generar: solo gastos reales y un aviso con los 3 gastos fijos pendientes (340)
        $before = $this->actingAs($this->superAdmin)->getJson('/api/finance/pnl?year=2026&month=9')->assertOk()->json();
        $this->assertEquals(100.00, $before['operating_expenses']['total']);
        $this->assertEquals(0.00, $before['taxes']['total']);
        $pending = collect($before['warnings'])->firstWhere('code', 'pending_recurring');
        $this->assertNotNull($pending);
        $this->assertEquals(3, $pending['count']);
        $this->assertEquals(340.00, $pending['amount']);

        $this->actingAs($this->superAdmin)
            ->postJson('/api/finance/recurring-expenses/generate', ['year' => 2026, 'month' => 9])
            ->assertCreated()
            ->assertJsonPath('created', 3);

        // Consultar P&L de Septiembre 2026
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/finance/pnl?year=2026&month=9');

        $response->assertOk();
        $data = $response->json();

        // Verificaciones matemáticas:
        // Ingresos locales: 1000 (la venta PedidosYa del POS cae en una semana sin importar: se suma aparte)
        $this->assertEquals(1000.00, $data['revenue']['local_sales']);
        $this->assertEquals(300.00, $data['revenue']['pedidosya_pos_unreconciled']);
        $this->assertEquals(1300.00, $data['revenue']['total']);

        // Costo de ventas: 250 + 150 = 400
        $this->assertEquals(400.00, $data['cost_of_sales']['total']);

        // Utilidad bruta: 1300 - 400 = 900
        $this->assertEquals(900.00, $data['gross_profit']['amount']);

        // Gastos operativos: 100 (directo) + 200 (mensual) + 100 (bimestral completo) = 400
        $this->assertEquals(400.00, $data['operating_expenses']['total']);

        // Utilidad operativa: 900 - 400 = 500
        $this->assertEquals(500.00, $data['operating_profit']['amount']);

        // Impuestos: 40
        $this->assertEquals(40.00, $data['taxes']['total']);

        // Utilidad neta: 500 - 40 = 460 (35,38 %)
        $this->assertEquals(460.00, $data['net_profit']['amount']);
        $this->assertEquals(35.38, $data['net_profit']['percentage']);

        // Ya no queda el aviso de gastos fijos pendientes
        $this->assertNull(collect($data['warnings'])->firstWhere('code', 'pending_recurring'));

        // Octubre: el bimestral no corresponde; mensual y cuota sí (pendientes, 240)
        $oct = $this->actingAs($this->superAdmin)->getJson('/api/finance/pnl?year=2026&month=10')->json();
        $this->assertEquals(0.00, $oct['operating_expenses']['total']);
        $this->assertEquals(240.00, collect($oct['warnings'])->firstWhere('code', 'pending_recurring')['amount']);

        // Agosto (antes del inicio): no corresponde ningún gasto fijo
        $aug = $this->actingAs($this->superAdmin)->getJson('/api/finance/pnl?year=2026&month=8')->json();
        $this->assertNull(collect($aug['warnings'])->firstWhere('code', 'pending_recurring'));
    }
}
