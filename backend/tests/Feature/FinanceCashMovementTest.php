<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\ExpenseCategory;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Clasificación de egresos de caja desde Finanzas y avisos del resumen (auditoría M4).
 */
class FinanceCashMovementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $cashier;
    private CashRegisterSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->session = CashRegisterSession::create([
            'cashier_id'     => $this->cashier->id,
            'status'         => 'closed',
            'opening_amount' => 200,
            'opened_at'      => '2026-09-15 08:00:00',
            'closed_at'      => '2026-09-15 20:00:00',
        ]);

        Sale::create([
            'id'           => Str::uuid()->toString(),
            'order_number' => 'LOC-1',
            'cashier_id'   => $this->cashier->id,
            'subtotal'     => 500,
            'total_amount' => 500,
            'status'       => 'completed',
            'source'       => 'pos',
            'created_at'   => '2026-09-15 12:00:00',
            'updated_at'   => '2026-09-15 12:00:00',
        ]);
    }

    private function movement(string $type, float $amount, ?int $categoryId = null, string $at = '2026-09-15 13:00:00'): CashMovement
    {
        return CashMovement::create([
            'cash_register_session_id' => $this->session->id,
            'user_id'                  => $this->cashier->id,
            'expense_category_id'      => $categoryId,
            'type'                     => $type,
            'payment_method'           => 'cash',
            'amount'                   => $amount,
            'description'              => 'Movimiento de prueba',
            'created_at'               => $at,
        ]);
    }

    private function warning(string $code): ?array
    {
        $warnings = $this->actingAs($this->owner)->getJson('/api/finance/pnl?year=2026&month=9')->json('warnings');

        return collect($warnings)->firstWhere('code', $code);
    }

    public function test_unclassified_cash_expenses_are_listed_warned_and_can_be_classified(): void
    {
        $fruit = ExpenseCategory::create(['name' => 'Frutas', 'section' => 'cost_of_sales', 'group_name' => 'Insumos']);
        $unclassified = $this->movement('expense', 210);
        $this->movement('expense', 50, $fruit->id);
        $this->movement('expense', 99, null, '2026-10-01 09:00:00'); // otro mes

        $list = $this->actingAs($this->owner)->getJson('/api/finance/cash-movements?year=2026&month=9&unclassified=1')->assertOk();
        $this->assertEquals(1, $list->json('count'));
        $this->assertEquals(210.0, $list->json('total'));

        $warning = $this->warning('unclassified_cash_movements');
        $this->assertEquals(1, $warning['count']);
        $this->assertEquals(210.0, $warning['amount']);

        $this->actingAs($this->owner)
            ->patchJson("/api/finance/cash-movements/{$unclassified->id}/category", ['expense_category_id' => $fruit->id])
            ->assertOk()
            ->assertJsonPath('movement.expense_category.name', 'Frutas');

        $this->assertNull($this->warning('unclassified_cash_movements'));

        $pnl = $this->actingAs($this->owner)->getJson('/api/finance/pnl?year=2026&month=9')->json();
        $this->assertEquals(260.0, $pnl['cost_of_sales']['total']);
        $this->assertEquals(0.0, $pnl['operating_expenses']['total']);
    }

    public function test_only_expenses_can_be_classified_and_only_into_active_categories(): void
    {
        $category = ExpenseCategory::create(['name' => 'Vieja', 'section' => 'operating', 'group_name' => 'Otros', 'is_active' => false]);
        $active = ExpenseCategory::create(['name' => 'Otros', 'section' => 'operating', 'group_name' => 'Otros']);
        $income = $this->movement('extra_income', 30);
        $expense = $this->movement('expense', 30);

        $this->actingAs($this->owner)
            ->patchJson("/api/finance/cash-movements/{$income->id}/category", ['expense_category_id' => $active->id])
            ->assertStatus(422);

        $this->actingAs($this->owner)
            ->patchJson("/api/finance/cash-movements/{$expense->id}/category", ['expense_category_id' => $category->id])
            ->assertStatus(422);

        $this->assertNull($expense->fresh()->expense_category_id);
    }

    public function test_cashier_cannot_classify_from_finance(): void
    {
        $category = ExpenseCategory::create(['name' => 'Otros', 'section' => 'operating', 'group_name' => 'Otros']);
        $expense = $this->movement('expense', 30);

        $this->actingAs($this->cashier)
            ->patchJson("/api/finance/cash-movements/{$expense->id}/category", ['expense_category_id' => $category->id])
            ->assertForbidden();
        $this->actingAs($this->cashier)->getJson('/api/finance/cash-movements?year=2026&month=9')->assertForbidden();
    }

    public function test_summary_warns_when_there_are_sales_but_no_purchases(): void
    {
        $this->assertNotNull($this->warning('no_purchases'));

        $fruit = ExpenseCategory::create(['name' => 'Frutas', 'section' => 'cost_of_sales', 'group_name' => 'Insumos']);
        $this->movement('expense', 80, $fruit->id);

        $this->assertNull($this->warning('no_purchases'));
    }
}
