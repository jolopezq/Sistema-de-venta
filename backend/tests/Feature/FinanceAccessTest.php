<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\RecurringExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $admin;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->admin      = User::factory()->create(['role' => 'admin']);
        $this->cashier    = User::factory()->create(['role' => 'cashier']);
    }

    public function test_cashier_and_admin_cannot_access_finance_pnl(): void
    {
        $this->actingAs($this->cashier)->getJson('/api/finance/pnl')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/finance/pnl')->assertForbidden();
        $this->actingAs($this->superAdmin)->getJson('/api/finance/pnl')->assertOk();
    }

    public function test_cashier_can_read_expense_categories_for_cash_movement(): void
    {
        $this->actingAs($this->cashier)->getJson('/api/expense-categories')->assertOk();
    }

    public function test_super_admin_can_manage_expenses_and_recurring(): void
    {
        $category = ExpenseCategory::firstOrCreate(
            ['name' => 'Helado y açaí'],
            ['section' => 'cost_of_sales', 'group_name' => 'Insumos', 'sort_order' => 1, 'is_active' => true]
        );

        // Crear gasto fuera de caja
        $res = $this->actingAs($this->superAdmin)->postJson('/api/finance/expenses', [
            'date'                => '2026-09-15',
            'expense_category_id' => $category->id,
            'supplier'            => 'Distribuidora Açaí',
            'amount'              => 350.50,
            'payment_method'      => 'bank_transfer',
            'notes'               => 'Compra semanal de pulpa',
        ]);

        $res->assertCreated();
        $expenseId = $res->json('expense.id');

        $this->assertDatabaseHas('finance_expenses', [
            'id'     => $expenseId,
            'amount' => 350.50,
        ]);

        // Crear gasto recurrente
        $resRec = $this->actingAs($this->superAdmin)->postJson('/api/finance/recurring-expenses', [
            'name'                => 'Alquiler mensual',
            'expense_category_id' => $category->id,
            'amount'              => 1500.00,
            'frequency'           => 'monthly',
            'day_of_month'        => 5,
        ]);

        $resRec->assertCreated();
        $recId = $resRec->json('expense.id');

        $this->assertDatabaseHas('recurring_expenses', [
            'id'     => $recId,
            'amount' => 1500.00,
        ]);
    }
}
