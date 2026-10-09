<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compras ligadas a insumos y proveedores (plan de inventario, F1): una compra es un gasto de Finanzas con líneas;
 * sube el stock, actualiza el costo promedio y, al editarla o eliminarla, se revierte.
 */
class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private int $categoryId;
    private Ingredient $pulpa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->categoryId = ExpenseCategory::where('section', 'cost_of_sales')->value('id');
        // 50 kg a Bs 12 como stock inicial (con su movimiento, como al crearlo desde la pantalla)
        $id = $this->actingAs($this->owner)->postJson('/api/ingredients', [
            'name' => 'Pulpa de açaí', 'unit' => 'kg', 'current_stock' => 50, 'unit_cost' => 12,
        ])->assertCreated()->json('data.id');
        $this->pulpa = Ingredient::findOrFail($id);
    }

    private function payload(array $items, array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-10-09', 'expense_category_id' => $this->categoryId, 'payment_method' => 'cash', 'items' => $items,
        ], $overrides);
    }

    private function buy(array $items, array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson('/api/purchases', $this->payload($items, $overrides));
    }

    private function line(Ingredient $ingredient, float $quantity, float $total, array $extra = []): array
    {
        return array_merge(['ingredient_id' => $ingredient->id, 'quantity' => $quantity, 'total' => $total], $extra);
    }

    public function test_a_purchase_creates_the_expense_the_stock_the_average_cost_and_the_movement(): void
    {
        $res = $this->buy([$this->line($this->pulpa, 10, 240)], ['supplier' => 'Frutas del Valle', 'receipt_number' => 'F-12'])
            ->assertCreated();

        $expense = FinanceExpense::findOrFail($res->json('expense.id'));
        $this->assertEquals(240.0, (float) $expense->amount);
        $this->assertSame('Frutas del Valle', $expense->supplier);
        $this->assertSame($this->categoryId, $expense->expense_category_id);

        $this->pulpa->refresh();
        $this->assertEquals(60.0, (float) $this->pulpa->current_stock);
        $this->assertEquals(14.0, (float) $this->pulpa->weighted_avg_cost, '(50×12 + 10×24) ÷ 60');
        $this->assertEquals(24.0, (float) $this->pulpa->unit_cost);

        $movement = InventoryMovement::where('ingredient_id', $this->pulpa->id)->where('type', 'restock')->sole();
        $this->assertEquals(10.0, (float) $movement->quantity_changed);
        $this->assertEquals(24.0, (float) $movement->unit_cost);
        $this->assertSame('purchase_item', $movement->reference_type);
        $this->assertSame($expense->items()->sole()->id, $movement->reference_id);
    }

    public function test_the_supplier_is_created_once_even_if_typed_differently(): void
    {
        $this->buy([$this->line($this->pulpa, 1, 24)], ['supplier' => 'Frutas del Valle'])->assertCreated();
        $this->buy([$this->line($this->pulpa, 1, 24)], ['supplier' => '  frutas   del valle '])->assertCreated();

        $this->assertSame(1, Supplier::count());
        $this->assertSame(2, FinanceExpense::where('supplier', 'Frutas del Valle')->count());
        $this->assertSame(1, Supplier::first()->delivery_days, 'sin dato, se asume 1 día de entrega');
    }

    public function test_the_purchase_unit_converts_to_the_consumption_unit(): void
    {
        // 3 bolsas de 2 kg por Bs 90 = 6 kg a Bs 15
        $this->buy([$this->line($this->pulpa, 3, 90, ['purchase_unit' => 'bolsa', 'factor' => 2])])->assertCreated();

        $item = FinanceExpense::first()->items()->sole();
        $this->assertEquals(6.0, (float) $item->stock_quantity);
        $this->assertEquals(15.0, (float) $item->unit_cost);
        $this->assertEquals(56.0, (float) $this->pulpa->fresh()->current_stock);
        $this->assertEquals(round((50 * 12 + 6 * 15) / 56, 4), (float) $this->pulpa->fresh()->weighted_avg_cost);
    }

    public function test_a_line_without_ingredient_is_only_an_expense(): void
    {
        $before = InventoryMovement::count();

        $this->buy([
            $this->line($this->pulpa, 2, 48),
            ['description' => 'Gas', 'quantity' => 1, 'total' => 22],
        ])->assertCreated();

        $expense = FinanceExpense::first();
        $this->assertEquals(70.0, (float) $expense->amount);
        $this->assertSame(2, $expense->items()->count());
        $this->assertSame($before + 1, InventoryMovement::count(), 'solo la línea del insumo mueve stock');
    }

    public function test_the_expense_counts_once_in_the_income_statement(): void
    {
        $this->buy([$this->line($this->pulpa, 10, 240)])->assertCreated();

        $pnl = $this->actingAs($this->owner)->getJson('/api/finance/pnl?year=2026&month=10')->assertOk()->json();

        $this->assertEquals(240.0, $pnl['cost_of_sales']['total']);
    }

    public function test_costing_components_cannot_be_bought_as_ingredients(): void
    {
        $component = Ingredient::create(['name' => 'Porción base', 'unit' => 'unidades', 'unit_cost' => 5, 'tracks_stock' => false]);

        $this->buy([$this->line($component, 1, 5)])->assertUnprocessable()->assertJsonValidationErrors('items.0.ingredient_id');
    }

    public function test_every_line_needs_an_ingredient_or_a_description(): void
    {
        $this->buy([['quantity' => 1, 'total' => 10]])->assertUnprocessable()->assertJsonValidationErrors('items.0.description');
        $this->buy([])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_editing_a_purchase_rebuilds_the_stock_and_the_average_cost(): void
    {
        $id = $this->buy([$this->line($this->pulpa, 10, 240)])->json('expense.id');

        $this->actingAs($this->owner)->putJson("/api/purchases/{$id}", $this->payload([$this->line($this->pulpa, 5, 120)]))
            ->assertOk()->assertJsonPath('warnings', []);

        $this->pulpa->refresh();
        $this->assertEquals(55.0, (float) $this->pulpa->current_stock);
        $this->assertEquals(round((50 * 12 + 5 * 24) / 55, 4), (float) $this->pulpa->weighted_avg_cost);
        $this->assertEquals(120.0, (float) FinanceExpense::findOrFail($id)->amount);
        $this->assertSame(1, FinanceExpense::findOrFail($id)->items()->count());
        $this->assertSame(1, InventoryMovement::where('type', 'purchase_reversal')->count(), 'queda el rastro de la reversa');
    }

    public function test_deleting_a_purchase_returns_the_stock_and_the_previous_average_cost(): void
    {
        $id = $this->buy([$this->line($this->pulpa, 10, 240)])->json('expense.id');

        $this->actingAs($this->owner)->deleteJson("/api/purchases/{$id}")->assertOk()->assertJsonPath('warnings', []);

        $this->pulpa->refresh();
        $this->assertEquals(50.0, (float) $this->pulpa->current_stock);
        $this->assertEquals(12.0, (float) $this->pulpa->weighted_avg_cost);
        $this->assertSoftDeleted('finance_expenses', ['id' => $id]);
        $this->assertSame(0, \App\Models\FinanceExpenseItem::count());
    }

    public function test_deleting_an_earlier_purchase_keeps_the_effect_of_a_later_one(): void
    {
        $first = $this->buy([$this->line($this->pulpa, 10, 240)])->json('expense.id'); // a Bs 24
        $this->buy([$this->line($this->pulpa, 10, 300)])->assertCreated();               // a Bs 30

        $this->actingAs($this->owner)->deleteJson("/api/purchases/{$first}")->assertOk();

        $this->pulpa->refresh();
        $this->assertEquals(60.0, (float) $this->pulpa->current_stock);
        $this->assertEquals(15.0, (float) $this->pulpa->weighted_avg_cost, '(50×12 + 10×30) ÷ 60, como si la primera no existiera');
        $this->assertEquals(30.0, (float) $this->pulpa->unit_cost);
    }

    public function test_the_average_is_kept_and_reported_when_old_movements_have_no_cost(): void
    {
        // Ingreso antiguo (antes de las compras): sin costo en el movimiento
        InventoryMovement::create([
            'ingredient_id' => $this->pulpa->id, 'quantity_changed' => 0, 'type' => 'restock', 'performed_by' => $this->owner->id,
        ]);
        $id = $this->buy([$this->line($this->pulpa, 10, 240)])->json('expense.id');
        $average = (float) $this->pulpa->fresh()->weighted_avg_cost;

        $res = $this->actingAs($this->owner)->deleteJson("/api/purchases/{$id}")->assertOk();

        $this->assertSame(['Pulpa de açaí'], $res->json('warnings'));
        $this->assertEquals(50.0, (float) $this->pulpa->fresh()->current_stock, 'el stock sí se devuelve');
        $this->assertEquals($average, (float) $this->pulpa->fresh()->weighted_avg_cost);
    }

    public function test_a_purchase_cannot_be_edited_as_a_plain_expense_but_can_be_deleted_from_finance(): void
    {
        $id = $this->buy([$this->line($this->pulpa, 10, 240)])->json('expense.id');

        $this->actingAs($this->owner)->putJson("/api/finance/expenses/{$id}", [
            'date' => '2026-10-09', 'expense_category_id' => $this->categoryId, 'amount' => 1, 'payment_method' => 'cash',
        ])->assertUnprocessable();

        $list = $this->actingAs($this->owner)->getJson('/api/finance/expenses?year=2026&month=10')->assertOk();
        $this->assertSame(1, $list->json('data.0.items_count'));

        $this->actingAs($this->owner)->deleteJson("/api/finance/expenses/{$id}")->assertOk();
        $this->assertEquals(50.0, (float) $this->pulpa->fresh()->current_stock, 'borrar desde Finanzas también devuelve el stock');
    }

    public function test_plain_expenses_keep_working_without_lines(): void
    {
        $this->actingAs($this->owner)->postJson('/api/finance/expenses', [
            'date' => '2026-10-09', 'expense_category_id' => $this->categoryId, 'amount' => 50, 'payment_method' => 'qr',
        ])->assertCreated();
        $expense = FinanceExpense::first();

        $this->actingAs($this->owner)->putJson("/api/finance/expenses/{$expense->id}", [
            'date' => '2026-10-09', 'expense_category_id' => $this->categoryId, 'amount' => 60, 'payment_method' => 'qr',
        ])->assertOk();
        $this->actingAs($this->owner)->deleteJson("/api/finance/expenses/{$expense->id}")->assertOk();
        $this->assertSoftDeleted($expense);
    }

    public function test_an_admin_can_register_purchases_but_a_cashier_cannot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->buy([$this->line($this->pulpa, 1, 24)], [], $admin)->assertCreated();
        $this->buy([$this->line($this->pulpa, 1, 24)], [], $cashier)->assertForbidden();
        $this->actingAs($cashier)->getJson('/api/purchases/options')->assertForbidden();
    }

    public function test_the_form_options_list_only_what_can_be_bought(): void
    {
        Ingredient::create(['name' => 'Porción base', 'unit' => 'unidades', 'unit_cost' => 5, 'tracks_stock' => false]);
        ExpenseCategory::where('section', '!=', 'cost_of_sales')->first();
        Supplier::create(['name' => 'Mercado Rodríguez', 'delivery_days' => 0]);
        Supplier::create(['name' => 'Cerrado', 'is_active' => false]);

        $options = $this->actingAs($this->owner)->getJson('/api/purchases/options')->assertOk()->json();

        $this->assertSame(['Pulpa de açaí'], array_column($options['ingredients'], 'name'));
        $this->assertSame(['Mercado Rodríguez'], array_column($options['suppliers'], 'name'));
        $this->assertNotEmpty($options['categories']);
        $this->assertEmpty(array_filter(
            $options['categories'],
            fn ($c) => ExpenseCategory::find($c['id'])->section !== 'cost_of_sales'
        ));
    }

    public function test_the_purchase_can_be_loaded_with_its_lines_to_edit_it(): void
    {
        $id = $this->buy([$this->line($this->pulpa, 3, 90, ['purchase_unit' => 'bolsa', 'factor' => 2])], ['supplier' => 'Mercado'])->json('expense.id');

        $this->actingAs($this->owner)->getJson("/api/purchases/{$id}")->assertOk()
            ->assertJsonPath('items.0.ingredient.name', 'Pulpa de açaí')
            ->assertJsonPath('items.0.purchase_unit', 'bolsa')
            ->assertJsonPath('supplier', 'Mercado');
    }

    // --- Proveedores ---

    public function test_suppliers_can_be_managed_and_names_are_not_duplicated(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/suppliers', [
            'name' => 'Frutas del Valle', 'phone' => '70000000', 'delivery_days' => 2, 'order_days' => 'lunes y jueves',
        ])->assertCreated()->json('id');

        $this->actingAs($this->owner)->postJson('/api/suppliers', ['name' => 'frutas  DEL valle'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->actingAs($this->owner)->putJson("/api/suppliers/{$id}", ['name' => 'Frutas del Valle', 'delivery_days' => 3])
            ->assertOk()->assertJsonPath('delivery_days', 3);

        $this->actingAs($this->owner)->deleteJson("/api/suppliers/{$id}")->assertOk();
        $this->assertSame(0, Supplier::count());
    }

    public function test_a_supplier_with_purchases_is_not_deleted(): void
    {
        $this->buy([$this->line($this->pulpa, 1, 24)], ['supplier' => 'Mercado'])->assertCreated();

        $this->actingAs($this->owner)->deleteJson('/api/suppliers/' . Supplier::first()->id)->assertUnprocessable();
        $this->assertSame(1, Supplier::count());
    }

    public function test_an_ingredient_keeps_its_purchase_data(): void
    {
        $supplier = Supplier::create(['name' => 'Distribuidora X', 'delivery_days' => 2]);

        $this->actingAs($this->owner)->putJson("/api/ingredients/{$this->pulpa->id}", [
            'name' => 'Pulpa de açaí', 'unit' => 'kg', 'supplier_id' => $supplier->id,
            'purchase_unit' => 'bolsa', 'purchase_factor' => 1, 'lot_size' => 2,
        ])->assertOk()
            ->assertJsonPath('data.purchase_unit', 'bolsa')
            ->assertJsonPath('data.supplier_id', $supplier->id)
            ->assertJsonPath('data.lot_size', 2);

        $this->actingAs($this->owner)->putJson("/api/ingredients/{$this->pulpa->id}", [
            'name' => 'Pulpa de açaí', 'unit' => 'kg', 'purchase_factor' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('purchase_factor');
    }
}
