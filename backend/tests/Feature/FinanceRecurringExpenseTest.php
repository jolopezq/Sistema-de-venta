<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\RecurringExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gastos fijos por período (auditoría de Finanzas A2) y regresiones de la lista de gastos.
 */
class FinanceRecurringExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private ExpenseCategory $opex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->opex = ExpenseCategory::create(['name' => 'Alquiler', 'section' => 'operating', 'group_name' => 'Local']);
    }

    private function template(array $overrides = []): RecurringExpense
    {
        return RecurringExpense::create(array_merge([
            'name'                => 'Alquiler local',
            'expense_category_id' => $this->opex->id,
            'amount'              => 1000,
            'frequency'           => 'monthly',
            'day_of_month'        => 5,
            'start_month'         => '2026-09',
            'is_active'           => true,
        ], $overrides));
    }

    private function generate(int $year, int $month, ?array $ids = null)
    {
        $payload = ['year' => $year, 'month' => $month];
        if ($ids !== null) {
            $payload['ids'] = $ids;
        }

        return $this->actingAs($this->owner)->postJson('/api/finance/recurring-expenses/generate', $payload);
    }

    private function opexOf(int $year, int $month): float
    {
        return (float) $this->actingAs($this->owner)
            ->getJson("/api/finance/pnl?year={$year}&month={$month}")
            ->json('operating_expenses.total');
    }

    public function test_a_template_is_not_counted_in_months_before_it_starts_nor_until_generated(): void
    {
        $this->template();

        // Antes del cambio: 1.000 aparecía en enero 2020, septiembre 2026 y diciembre 2030
        $this->assertEquals(0.0, $this->opexOf(2020, 1));
        $this->assertEquals(0.0, $this->opexOf(2026, 9));
        $this->assertEquals(0.0, $this->opexOf(2030, 12));

        $this->generate(2026, 9)->assertCreated();
        $this->assertEquals(1000.0, $this->opexOf(2026, 9));
        $this->assertEquals(0.0, $this->opexOf(2026, 10));

        // Antes del inicio no hay nada que generar
        $this->generate(2026, 8)->assertOk()->assertJsonPath('created', 0);
        $this->assertDatabaseCount('finance_expenses', 1);
    }

    public function test_generating_twice_the_same_month_does_not_duplicate(): void
    {
        $this->template();

        $this->generate(2026, 9)->assertCreated()->assertJsonPath('created', 1);
        $this->generate(2026, 9)->assertOk()->assertJsonPath('created', 0);

        $this->assertEquals(1, FinanceExpense::where('period', '2026-09')->count());
        $this->assertEquals(1000.0, $this->opexOf(2026, 9));
    }

    public function test_generated_expense_uses_the_payment_day_capped_to_the_month_length(): void
    {
        $tpl = $this->template(['day_of_month' => 31, 'start_month' => '2027-01', 'payment_method' => 'qr']);

        $this->generate(2027, 2)->assertCreated();

        $expense = FinanceExpense::where('recurring_expense_id', $tpl->id)->first();
        $this->assertEquals('2027-02-28', $expense->date->toDateString());
        $this->assertEquals('2027-02', $expense->period);
        $this->assertEquals('qr', $expense->payment_method);
        $this->assertEquals('Alquiler local', $expense->supplier);

        // El último día del mes cuenta en el Estado de Resultados (antes quedaba fuera)
        $this->assertEquals(1000.0, $this->opexOf(2027, 2));
    }

    public function test_non_monthly_templates_are_due_in_their_month_with_the_full_amount(): void
    {
        $this->template(['name' => 'Seguro', 'amount' => 1200, 'frequency' => 'annual', 'start_month' => '2026-03']);
        $this->template(['name' => 'Fumigación', 'amount' => 300, 'frequency' => 'quarterly', 'start_month' => '2026-09']);
        $this->template(['name' => 'Mantenimiento', 'amount' => 100, 'frequency' => 'bimonthly', 'start_month' => '2026-09']);

        $pendingNames = fn (int $y, int $m) => collect(
            $this->actingAs($this->owner)->getJson("/api/finance/recurring-expenses/pending?year={$y}&month={$m}")->json('items')
        )->pluck('name')->sort()->values()->all();

        $this->assertEquals(['Fumigación', 'Mantenimiento'], $pendingNames(2026, 9));
        $this->assertEquals([], $pendingNames(2026, 10));
        $this->assertEquals(['Mantenimiento'], $pendingNames(2026, 11));
        $this->assertEquals(['Fumigación'], $pendingNames(2026, 12));
        $this->assertEquals(['Fumigación', 'Mantenimiento', 'Seguro'], $pendingNames(2027, 3));

        $this->generate(2027, 3)->assertCreated()->assertJsonPath('created', 3);
        $this->assertEquals(1600.0, $this->opexOf(2027, 3));
    }

    public function test_changing_the_template_amount_does_not_rewrite_past_months(): void
    {
        $tpl = $this->template();
        $this->generate(2026, 9);

        $this->actingAs($this->owner)->putJson("/api/finance/recurring-expenses/{$tpl->id}", [
            'name'                => 'Alquiler local',
            'expense_category_id' => $this->opex->id,
            'amount'              => 1200,
            'frequency'           => 'monthly',
        ])->assertOk();

        $this->assertEquals('2026-09', $tpl->fresh()->start_month);
        $this->generate(2026, 10);

        $this->assertEquals(1000.0, $this->opexOf(2026, 9));
        $this->assertEquals(1200.0, $this->opexOf(2026, 10));
    }

    public function test_a_deleted_generated_expense_is_not_proposed_again_unless_requested(): void
    {
        $tpl = $this->template();
        $this->generate(2026, 9);
        $expense = FinanceExpense::where('recurring_expense_id', $tpl->id)->first();

        $this->actingAs($this->owner)->deleteJson("/api/finance/expenses/{$expense->id}")->assertOk();
        $this->assertSoftDeleted('finance_expenses', ['id' => $expense->id]);

        $pending = $this->actingAs($this->owner)->getJson('/api/finance/recurring-expenses/pending?year=2026&month=9')->json();
        $this->assertEquals(0, $pending['pending_count']);
        $this->assertEquals('dismissed', $pending['items'][0]['status']);

        $this->generate(2026, 9)->assertOk()->assertJsonPath('created', 0);
        $this->assertEquals(0.0, $this->opexOf(2026, 9));

        // Pedido explícito: se recupera el mismo gasto
        $this->generate(2026, 9, [$tpl->id])->assertCreated()->assertJsonPath('restored', 1);
        $this->assertEquals(1000.0, $this->opexOf(2026, 9));
        $this->assertEquals(1, FinanceExpense::withTrashed()->where('period', '2026-09')->count());
    }

    public function test_inactive_templates_are_not_pending_but_keep_what_was_generated(): void
    {
        $tpl = $this->template();
        $this->generate(2026, 9);
        $this->actingAs($this->owner)->patchJson("/api/finance/recurring-expenses/{$tpl->id}/toggle-active")->assertOk();

        $sep = $this->actingAs($this->owner)->getJson('/api/finance/recurring-expenses/pending?year=2026&month=9')->json();
        $this->assertEquals('generated', $sep['items'][0]['status']);

        $oct = $this->actingAs($this->owner)->getJson('/api/finance/recurring-expenses/pending?year=2026&month=10')->json();
        $this->assertEquals([], $oct['items']);
    }

    public function test_new_template_starts_in_the_current_month_by_default(): void
    {
        $this->actingAs($this->owner)->postJson('/api/finance/recurring-expenses', [
            'name'                => 'Internet',
            'expense_category_id' => $this->opex->id,
            'amount'              => 150,
            'frequency'           => 'monthly',
        ])->assertCreated()
          ->assertJsonPath('expense.start_month', now()->format('Y-m'))
          ->assertJsonPath('expense.payment_method', 'bank_transfer');
    }

    public function test_expenses_can_be_updated_and_deleted_and_last_day_is_listed(): void
    {
        $expense = FinanceExpense::create([
            'date'                => '2026-09-30',
            'expense_category_id' => $this->opex->id,
            'amount'              => 10,
            'payment_method'      => 'qr',
            'user_id'             => $this->owner->id,
        ]);

        // El último día del mes aparece en la lista del mes
        $list = $this->actingAs($this->owner)->getJson('/api/finance/expenses?year=2026&month=9')->assertOk();
        $this->assertCount(1, $list->json('data'));

        // Editar cambia el registro (antes respondía 200 sin modificar nada)
        $this->actingAs($this->owner)->putJson("/api/finance/expenses/{$expense->id}", [
            'date'                => '2026-09-30',
            'expense_category_id' => $this->opex->id,
            'amount'              => 99,
            'payment_method'      => 'qr',
        ])->assertOk()->assertJsonPath('expense.amount', '99.00');
        $this->assertEquals(99.0, (float) $expense->fresh()->amount);

        // Eliminar lo elimina
        $this->actingAs($this->owner)->deleteJson("/api/finance/expenses/{$expense->id}")->assertOk();
        $this->assertSoftDeleted('finance_expenses', ['id' => $expense->id]);

        // Un id inexistente responde 404
        $this->actingAs($this->owner)->deleteJson('/api/finance/expenses/999999')->assertNotFound();
    }

    public function test_cashier_cannot_generate_recurring_expenses(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->template();

        $this->actingAs($cashier)->postJson('/api/finance/recurring-expenses/generate', ['year' => 2026, 'month' => 9])
            ->assertForbidden();
        $this->assertDatabaseCount('finance_expenses', 0);
    }
}
