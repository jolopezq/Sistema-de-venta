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

    public function test_cashier_and_admin_cannot_use_the_new_finance_endpoints(): void
    {
        $session = \App\Models\CashRegisterSession::create([
            'cashier_id'     => $this->cashier->id,
            'status'         => 'open',
            'opening_amount' => 100,
            'opened_at'      => now(),
        ]);
        $movement = \App\Models\CashMovement::create([
            'cash_register_session_id' => $session->id,
            'user_id'                  => $this->cashier->id,
            'type'                     => 'expense',
            'payment_method'           => 'cash',
            'amount'                   => 10,
            'description'              => 'Hielo',
        ]);

        $routes = [
            ['get', '/api/finance/recurring-expenses/pending?year=2026&month=9'],
            ['post', '/api/finance/recurring-expenses/generate'],
            ['get', '/api/finance/cash-movements?year=2026&month=9'],
            ['patch', "/api/finance/cash-movements/{$movement->id}/category"],
            ['post', '/api/finance/pedidosya-settlements/preview'],
        ];

        foreach ([$this->cashier, $this->admin] as $user) {
            foreach ($routes as [$method, $uri]) {
                $this->actingAs($user)->json($method, $uri, ['year' => 2026, 'month' => 9])->assertForbidden();
            }
        }
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

    /** Finanzas por persona (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 2): «Ver» consulta, «Editar» registra. */
    public function test_admin_with_finance_read_can_consult_but_not_write(): void
    {
        \App\Models\UserPermission::create(['user_id' => $this->admin->id, 'module' => 'finance', 'access_level' => 'read']);
        $category = ExpenseCategory::firstOrCreate(
            ['name' => 'Helado y açaí'],
            ['section' => 'cost_of_sales', 'group_name' => 'Insumos', 'sort_order' => 1, 'is_active' => true]
        );

        foreach (['/api/finance/pnl?year=2026&month=9', '/api/finance/break-even?year=2026&month=9', '/api/finance/expenses?year=2026&month=9',
                  '/api/finance/cash-movements?year=2026&month=9', '/api/finance/costs', '/api/finance/recurring-expenses'] as $uri) {
            $this->actingAs($this->admin)->getJson($uri)->assertOk();
        }
        $this->actingAs($this->admin)->postJson('/api/finance/expenses', [
            'date' => '2026-09-15', 'expense_category_id' => $category->id, 'amount' => 10, 'payment_method' => 'cash',
        ])->assertForbidden();
        $this->actingAs($this->admin)->postJson('/api/finance/recurring-expenses/generate', ['year' => 2026, 'month' => 9])->assertForbidden();
        $this->actingAs($this->admin)->postJson('/api/finance/categories', ['name' => 'X'])->assertForbidden();
    }

    public function test_admin_with_finance_edit_can_register_expenses(): void
    {
        \App\Models\UserPermission::create(['user_id' => $this->admin->id, 'module' => 'finance', 'access_level' => 'edit']);
        $category = ExpenseCategory::firstOrCreate(
            ['name' => 'Helado y açaí'],
            ['section' => 'cost_of_sales', 'group_name' => 'Insumos', 'sort_order' => 1, 'is_active' => true]
        );

        $this->actingAs($this->admin)->postJson('/api/finance/expenses', [
            'date' => '2026-09-15', 'expense_category_id' => $category->id, 'amount' => 10, 'payment_method' => 'cash',
        ])->assertCreated();
    }

    public function test_a_cashier_never_gets_finance_even_with_a_row_written_directly(): void
    {
        \App\Models\UserPermission::create(['user_id' => $this->cashier->id, 'module' => 'finance', 'access_level' => 'edit']);
        $this->actingAs($this->cashier)->getJson('/api/finance/pnl')->assertForbidden();
    }
}
