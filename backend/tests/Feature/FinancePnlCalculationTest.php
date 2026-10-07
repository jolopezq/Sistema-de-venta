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

        // 6. Gasto fijo recurrente mensual (200 Bs mensual)
        RecurringExpense::create([
            'name'                => 'Alquiler local',
            'expense_category_id' => $opexCat->id,
            'amount'              => 200.00,
            'frequency'           => 'monthly',
            'is_active'           => true,
        ]);

        // 7. Gasto fijo recurrente bimestral (100 Bs bimestral -> 50 Bs en el mes)
        RecurringExpense::create([
            'name'                => 'Mantenimiento preventivo',
            'expense_category_id' => $opexCat->id,
            'amount'              => 100.00,
            'frequency'           => 'bimonthly',
            'is_active'           => true,
        ]);

        // 8. Impuesto fijo mensual (40 Bs)
        RecurringExpense::create([
            'name'                => 'Cuota Régimen Simplificado',
            'expense_category_id' => $taxCat->id,
            'amount'              => 40.00,
            'frequency'           => 'monthly',
            'is_active'           => true,
        ]);

        // Consultar P&L de Septiembre 2026
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/finance/pnl?year=2026&month=9');

        $response->assertOk();
        $data = $response->json();

        // Verificaciones matemáticas:
        // Ingresos locales: 1000
        $this->assertEquals(1000.00, $data['revenue']['local_sales']);
        $this->assertEquals(1000.00, $data['revenue']['total']);

        // Costo de ventas: 250 + 150 = 400 (40%)
        $this->assertEquals(400.00, $data['cost_of_sales']['total']);
        $this->assertEquals(40.0, $data['cost_of_sales']['percentage']);

        // Utilidad bruta: 1000 - 400 = 600 (60%)
        $this->assertEquals(600.00, $data['gross_profit']['amount']);
        $this->assertEquals(60.0, $data['gross_profit']['percentage']);

        // Gastos operativos: 100 (directo) + 200 (mensual) + 50 (bimestral prorrateado) = 350 (35%)
        $this->assertEquals(350.00, $data['operating_expenses']['total']);
        $this->assertEquals(35.0, $data['operating_expenses']['percentage']);

        // Utilidad operativa: 600 - 350 = 250 (25%)
        $this->assertEquals(250.00, $data['operating_profit']['amount']);
        $this->assertEquals(25.0, $data['operating_profit']['percentage']);

        // Impuestos: 40
        $this->assertEquals(40.00, $data['taxes']['total']);

        // Utilidad neta: 250 - 40 = 210 (21%)
        $this->assertEquals(210.00, $data['net_profit']['amount']);
        $this->assertEquals(21.0, $data['net_profit']['percentage']);
    }
}
