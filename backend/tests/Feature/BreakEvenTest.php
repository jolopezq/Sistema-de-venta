<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\PedidosYaSettlement;
use App\Models\RecurringExpense;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemOption;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsOhanaCatalog;
use Tests\TestCase;

/**
 * Margen de contribución y punto de equilibrio (docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md §3).
 */
class BreakEvenTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOhanaCatalog;

    private User $owner;
    private ExpenseCategory $rent;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 12, 0, 0, 'America/La_Paz'));
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->buildCatalog();
        $this->rent = ExpenseCategory::where('name', 'Alquiler local')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(string $at, string $source, string $product, int $qty, float $unit, array $options = []): void
    {
        $sale = Sale::create([
            'id' => Str::uuid()->toString(), 'order_number' => 'T-' . Str::random(6), 'cashier_id' => $this->owner->id,
            'subtotal' => $qty * $unit, 'total_amount' => $qty * $unit, 'status' => 'completed', 'source' => $source,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_id' => $this->p[$product]->id, 'quantity' => $qty, 'unit_price' => $unit, 'subtotal' => $qty * $unit]);
        foreach ($options as $name) {
            SaleItemOption::create([
                'sale_item_id' => $item->id, 'option_id' => $this->o[$name]->id, 'option_group_id' => $this->o[$name]->option_group_id,
                'option_name_snapshot' => $name, 'additional_price_snapshot' => 0, 'quantity' => 1,
            ]);
        }
    }

    private function breakEven(int $month = 9): array
    {
        return $this->actingAs($this->owner)->getJson("/api/finance/break-even?year=2026&month={$month}")->assertOk()->json();
    }

    public function test_break_even_with_recipes_fixed_costs_and_daily_target(): void
    {
        $this->applyBaseRecipes();
        // 10 Classic Bowl a 28 = 280 de ventas; costo 9,20 c/u = 92 → margen 67,1 %
        $this->sale('2026-09-05 15:00:00', 'pos', 'Classic Bowl', 10, 28);

        RecurringExpense::create(['name' => 'Alquiler', 'expense_category_id' => $this->rent->id, 'amount' => 2500, 'frequency' => 'monthly', 'start_month' => '2026-09', 'is_active' => true]);
        // Anual de 1.200 → 100 por mes; no corresponde generarlo para que cuente
        RecurringExpense::create(['name' => 'Patente', 'expense_category_id' => $this->rent->id, 'amount' => 1200, 'frequency' => 'annual', 'start_month' => '2026-01', 'is_active' => true]);
        // Uno que empieza después no cuenta
        RecurringExpense::create(['name' => 'Futuro', 'expense_category_id' => $this->rent->id, 'amount' => 999, 'frequency' => 'monthly', 'start_month' => '2026-12', 'is_active' => true]);
        // Gasto fijo del mes que no está en la lista (reparación): 400
        $maint = ExpenseCategory::where('name', 'Mantenimiento y reparaciones')->first();
        FinanceExpense::create(['date' => '2026-09-08', 'expense_category_id' => $maint->id, 'amount' => 400, 'payment_method' => 'qr', 'user_id' => $this->owner->id]);

        $r = $this->breakEven();

        $this->assertEquals('recipes', $r['variable']['source']);
        $this->assertEqualsWithDelta(92.0, $r['variable']['product_cost'], 0.01);
        $this->assertEquals(67.1, $r['contribution']['percent']);
        $this->assertEquals(2500 + 100 + 400, $r['fixed']['total']);
        $this->assertEquals(2600, $r['fixed']['recurring_monthly']);

        // 3.000 ÷ 0,671 = 4.470,94; meta diaria ÷ 30
        $this->assertEqualsWithDelta(3000 / 0.671, $r['break_even']['amount'], 0.5);
        $this->assertEqualsWithDelta(3000 / 0.671 / 30, $r['break_even']['daily'], 0.05);
        $this->assertEquals(28.0, $r['break_even']['unit_price']);
        $this->assertEqualsWithDelta(3000 / 0.671 / 30 / 28, $r['break_even']['units_per_day'], 0.1);

        // Progreso al día 15: 280 vendidos, ritmo 18,67 por día
        $this->assertEquals(15, $r['progress']['elapsed_days']);
        $this->assertEquals('behind', $r['progress']['status']);
        $this->assertEqualsWithDelta(560.0, $r['progress']['projection'], 0.01);
        $this->assertFalse($r['progress']['closed']);
    }

    public function test_closed_month_reports_the_result_instead_of_the_pace(): void
    {
        $this->applyBaseRecipes();
        $this->sale('2026-08-05 15:00:00', 'pos', 'Classic Bowl', 10, 28);
        RecurringExpense::create(['name' => 'Alquiler', 'expense_category_id' => $this->rent->id, 'amount' => 2500, 'frequency' => 'monthly', 'start_month' => '2026-08', 'is_active' => true]);

        $r = $this->breakEven(8);

        $this->assertTrue($r['progress']['closed']);
        $this->assertEquals('missed', $r['progress']['status']);
        $this->assertEquals(31, $r['progress']['elapsed_days']);
        // La proyección de un mes cerrado son sus ventas reales: 280 × 67,1 % − 2.500
        $this->assertEqualsWithDelta(280.0, $r['progress']['projection'], 0.01);
        $this->assertEqualsWithDelta(280 * 0.671 - 2500, $r['progress']['projected_profit'], 0.5);
    }

    public function test_reached_day_is_the_first_day_sales_cover_the_break_even(): void
    {
        $this->applyBaseRecipes();
        // Margen 67,1 % y fijos de 100 → equilibrio en Bs 149,03
        RecurringExpense::create(['name' => 'Alquiler', 'expense_category_id' => $this->rent->id, 'amount' => 100, 'frequency' => 'monthly', 'start_month' => '2026-08', 'is_active' => true]);
        $this->sale('2026-08-03 15:00:00', 'pos', 'Classic Bowl', 5, 28); // acumulado 140: todavía no
        $this->sale('2026-08-10 11:00:00', 'pos', 'Classic Bowl', 5, 28); // acumulado 280: se cubre el día 10
        $this->sale('2026-08-20 18:00:00', 'pos', 'Classic Bowl', 5, 28);

        $r = $this->breakEven(8);

        $this->assertEquals('reached', $r['progress']['status']);
        $this->assertSame(10, $r['progress']['reached_day']);
    }

    public function test_reached_day_is_null_when_the_break_even_is_not_reached(): void
    {
        $this->applyBaseRecipes();
        $this->sale('2026-08-05 15:00:00', 'pos', 'Classic Bowl', 10, 28);
        RecurringExpense::create(['name' => 'Alquiler', 'expense_category_id' => $this->rent->id, 'amount' => 2500, 'frequency' => 'monthly', 'start_month' => '2026-08', 'is_active' => true]);

        $r = $this->breakEven(8);

        $this->assertEquals('missed', $r['progress']['status']);
        $this->assertNull($r['progress']['reached_day']);
    }

    public function test_generated_recurring_expenses_are_not_counted_twice(): void
    {
        $this->applyBaseRecipes();
        $this->sale('2026-09-05 15:00:00', 'pos', 'Classic Bowl', 10, 28);
        $tpl = RecurringExpense::create(['name' => 'Alquiler', 'expense_category_id' => $this->rent->id, 'amount' => 2500, 'frequency' => 'monthly', 'start_month' => '2026-09', 'is_active' => true]);
        $this->actingAs($this->owner)->postJson('/api/finance/recurring-expenses/generate', ['year' => 2026, 'month' => 9])->assertCreated();
        $this->assertEquals(1, FinanceExpense::where('recurring_expense_id', $tpl->id)->count());

        $this->assertEquals(2500, $this->breakEven()['fixed']['total']);
    }

    public function test_pedidosya_platform_costs_are_variable_and_lower_its_margin(): void
    {
        $this->applyBaseRecipes();
        $this->sale('2026-09-05 15:00:00', 'pos', 'Classic Bowl', 10, 28);
        // Semana importada 07–13/09: ventas netas 300; plataforma 75 comisión + 15 publicidad + 10 IVA − 10 reintegros = 90
        PedidosYaSettlement::create([
            'start_date' => '2026-09-07', 'end_date' => '2026-09-13', 'net_sales' => 300, 'service_fees' => 75, 'advertising' => 15,
            'taxes' => 10, 'refunds' => 10, 'total_payout' => 210, 'calculated_payout' => 210, 'csv_gross_sales' => 300, 'user_id' => $this->owner->id,
        ]);
        // Las ventas PedidosYa de esa semana (para el % de costo del canal): 10 Patriota a 30
        $this->sale('2026-09-09 19:00:00', 'pedidosya', 'Patriota', 10, 30);
        RecurringExpense::create(['name' => 'Alquiler', 'expense_category_id' => $this->rent->id, 'amount' => 1000, 'frequency' => 'monthly', 'start_month' => '2026-09', 'is_active' => true]);

        $r = $this->breakEven();

        $this->assertEquals(90.0, $r['variable']['platform']); // 75 + 15 + 10 − 10
        $this->assertEquals(67.1, $r['contribution']['local_percent']);
        // PedidosYa: 300 − 92 (9,20 × 10 sobre 300 → 30,67 %) − 90 = 118 → 39,3 %
        $this->assertEquals(39.3, $r['contribution']['pedidosya_percent']);
        $this->assertEqualsWithDelta((580 - 92 - 92 - 90) / 580 * 100, $r['contribution']['percent'], 0.1);
    }

    public function test_variable_categories_and_unclassified_cash_expenses(): void
    {
        $this->applyBaseRecipes();
        $this->sale('2026-09-05 15:00:00', 'pos', 'Classic Bowl', 10, 28);
        RecurringExpense::create(['name' => 'Alquiler', 'expense_category_id' => $this->rent->id, 'amount' => 1000, 'frequency' => 'monthly', 'start_month' => '2026-09', 'is_active' => true]);

        // Comisión bancaria marcada como variable: suma a los costos variables, no a los fijos
        $fee = ExpenseCategory::where('section', 'financial')->first();
        $this->actingAs($this->owner)->putJson("/api/finance/categories/{$fee->id}", ['name' => $fee->name, 'section' => 'financial', 'cost_behavior' => 'variable'])->assertOk();
        FinanceExpense::create(['date' => '2026-09-08', 'expense_category_id' => $fee->id, 'amount' => 14, 'payment_method' => 'qr', 'user_id' => $this->owner->id]);

        // Las compras de insumos no se suman (ya están en las recetas): se muestran como costo real
        $fruit = ExpenseCategory::where('section', 'cost_of_sales')->first();
        FinanceExpense::create(['date' => '2026-09-08', 'expense_category_id' => $fruit->id, 'amount' => 120, 'payment_method' => 'qr', 'user_id' => $this->owner->id]);

        // Egreso de caja sin clasificar: cuenta como fijo hasta clasificarlo
        $session = CashRegisterSession::create(['cashier_id' => $this->owner->id, 'status' => 'closed', 'opening_amount' => 100, 'opened_at' => '2026-09-08 08:00:00', 'closed_at' => '2026-09-08 20:00:00']);
        CashMovement::create(['cash_register_session_id' => $session->id, 'user_id' => $this->owner->id, 'type' => 'expense', 'payment_method' => 'cash', 'amount' => 50, 'description' => 'Hielo', 'created_at' => '2026-09-08 10:00:00']);

        $r = $this->breakEven();

        $this->assertEquals(14.0, $r['variable']['other']);
        $this->assertEqualsWithDelta(92 + 14, $r['variable']['total'], 0.01);
        $this->assertEquals(1050.0, $r['fixed']['total']);
        $this->assertEquals(120.0, $r['real_cost_of_sales']['total']);
        $this->assertEquals(42.9, $r['real_cost_of_sales']['percent']);
    }

    public function test_without_recipes_the_margin_comes_from_purchases_and_warns(): void
    {
        $this->sale('2026-09-05 15:00:00', 'pos', 'Classic Bowl', 10, 28);
        $fruit = ExpenseCategory::where('section', 'cost_of_sales')->first();
        FinanceExpense::create(['date' => '2026-09-08', 'expense_category_id' => $fruit->id, 'amount' => 84, 'payment_method' => 'qr', 'user_id' => $this->owner->id]);

        $r = $this->breakEven();

        $this->assertEquals('purchases', $r['variable']['source']);
        $this->assertEquals(70.0, $r['contribution']['percent']);
        $codes = collect($r['warnings'])->pluck('code');
        $this->assertContains('purchases_based', $codes);
        $this->assertContains('no_fixed_costs', $codes);
        $this->assertNull($r['break_even']);
    }

    public function test_a_month_without_sales_uses_the_previous_month_margin(): void
    {
        $this->applyBaseRecipes();
        $this->sale('2026-08-20 15:00:00', 'pos', 'Classic Bowl', 10, 28);
        RecurringExpense::create(['name' => 'Alquiler', 'expense_category_id' => $this->rent->id, 'amount' => 1000, 'frequency' => 'monthly', 'start_month' => '2026-08', 'is_active' => true]);

        $r = $this->breakEven();

        $this->assertEquals('previous_month', $r['contribution']['based_on']);
        $this->assertEquals(67.1, $r['contribution']['percent']);
        $this->assertNotNull($r['break_even']);
        $this->assertContains('previous_month', collect($r['warnings'])->pluck('code'));
    }

    public function test_only_the_owner_sees_the_break_even(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->getJson('/api/finance/break-even')->assertForbidden();
    }
}
