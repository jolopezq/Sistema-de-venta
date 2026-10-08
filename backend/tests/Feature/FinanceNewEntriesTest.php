<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registrar lo que no está en la lista: categorías nuevas desde los formularios (sin duplicados)
 * y proveedores sugeridos / unificados.
 */
class FinanceNewEntriesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
    }

    public function test_owner_creates_a_category_that_is_not_in_the_list_and_it_goes_last_in_its_section(): void
    {
        $maxOperating = (int) ExpenseCategory::where('section', 'operating')->max('sort_order');

        $res = $this->actingAs($this->owner)->postJson('/api/finance/categories', [
            'name'    => '  Gas   licuado ',
            'section' => 'operating',
        ])->assertCreated();

        $res->assertJsonPath('category.name', 'Gas licuado')
            ->assertJsonPath('category.is_active', true)
            ->assertJsonPath('category.sort_order', $maxOperating + 1);

        // Se puede usar de inmediato en un gasto y aparece en el Estado de Resultados
        $this->actingAs($this->owner)->postJson('/api/finance/expenses', [
            'date' => '2026-09-10', 'expense_category_id' => $res->json('category.id'), 'amount' => 85, 'payment_method' => 'qr',
        ])->assertCreated();

        $pnl = $this->actingAs($this->owner)->getJson('/api/finance/pnl?year=2026&month=9')->json();
        $this->assertContains('Gas licuado', collect($pnl['operating_expenses']['by_category'])->pluck('name')->all());
    }

    public function test_duplicate_category_names_are_rejected_regardless_of_case_accents_or_spaces(): void
    {
        $this->actingAs($this->owner)->postJson('/api/finance/categories', ['name' => 'Gas licuado', 'section' => 'operating'])->assertCreated();

        $this->actingAs($this->owner)->postJson('/api/finance/categories', ['name' => 'GÁS  Licuado', 'section' => 'cost_of_sales'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name')
            ->assertJsonPath('errors.name.0', 'Ya existe la categoría «Gas licuado».');

        // Una seed existente también cuenta, aunque esté desactivada
        $seed = ExpenseCategory::where('name', 'Internet y teléfono')->first();
        $seed->update(['is_active' => false]);
        $this->actingAs($this->owner)->postJson('/api/finance/categories', ['name' => 'internet y telefono', 'section' => 'operating'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Ya existe la categoría «Internet y teléfono», desactivada: actívala en Finanzas → Categorías.');

        // Renombrar una categoría con su mismo nombre no choca consigo misma
        $this->actingAs($this->owner)->putJson("/api/finance/categories/{$seed->id}", ['name' => 'Internet y teléfono', 'section' => 'operating'])
            ->assertOk();
    }

    public function test_cashier_cannot_create_categories(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)->postJson('/api/finance/categories', ['name' => 'Propinas', 'section' => 'operating'])
            ->assertForbidden();
        $this->assertDatabaseMissing('expense_categories', ['name' => 'Propinas']);
    }

    public function test_suppliers_are_suggested_with_their_last_category_and_unified_when_saved(): void
    {
        $fruit = ExpenseCategory::where('section', 'cost_of_sales')->first();
        $other = ExpenseCategory::where('section', 'operating')->first();

        $post = fn (string $supplier, int $categoryId, string $date) => $this->actingAs($this->owner)->postJson('/api/finance/expenses', [
            'date' => $date, 'expense_category_id' => $categoryId, 'supplier' => $supplier, 'amount' => 10, 'payment_method' => 'qr',
        ])->assertCreated();

        $post('Frutas del Valle', $other->id, '2026-09-01');
        $post('Frutas del Valle', $fruit->id, '2026-09-08');
        // Escrito distinto: se guarda con la forma ya registrada
        $res = $post('  frutas  DEL valle ', $fruit->id, '2026-09-15');
        $res->assertJsonPath('expense.supplier', 'Frutas del Valle');
        $post('Plásticos SC', $other->id, '2026-09-02');

        $suppliers = $this->actingAs($this->owner)->getJson('/api/finance/suppliers')->assertOk()->json();

        $this->assertCount(2, $suppliers);
        $this->assertEquals('Frutas del Valle', $suppliers[0]['name']);
        $this->assertEquals(3, $suppliers[0]['count']);
        $this->assertEquals($fruit->id, $suppliers[0]['expense_category_id']);
        $this->assertEquals(1, FinanceExpense::distinct()->whereNotNull('supplier')->where('supplier', 'like', 'Frutas%')->count('supplier'));
    }

    public function test_editing_an_expense_without_supplier_field_keeps_the_supplier(): void
    {
        $cat = ExpenseCategory::first();
        $expense = FinanceExpense::create([
            'date' => '2026-09-10', 'expense_category_id' => $cat->id, 'supplier' => 'Mercado', 'amount' => 10,
            'payment_method' => 'qr', 'user_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->putJson("/api/finance/expenses/{$expense->id}", [
            'date' => '2026-09-10', 'expense_category_id' => $cat->id, 'amount' => 12, 'payment_method' => 'qr',
        ])->assertOk();

        $this->assertEquals('Mercado', $expense->fresh()->supplier);
    }
}
