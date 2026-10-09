<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Recipe;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsOhanaCatalog;
use Tests\TestCase;

/**
 * Fase 0 del plan de inventario: costo promedio correcto, stock que solo cambia por movimientos,
 * productos por peso por gramos y validaciones (hallazgos H5, H6, H7, H8, H9, H10, H11, H15 de la auditoría).
 */
class InventoryStockTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOhanaCatalog;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->buildCatalog();
    }

    private function createIngredient(array $overrides = []): Ingredient
    {
        $id = $this->actingAs($this->owner)->postJson('/api/ingredients', array_merge([
            'name' => 'Pulpa de açaí', 'unit' => 'kg', 'current_stock' => 50, 'unit_cost' => 12,
        ], $overrides))->assertCreated()->json('data.id');

        return Ingredient::findOrFail($id);
    }

    /** Un ingreso de mercadería es una Compra de una línea: también crea el gasto en Finanzas. */
    private function restock(Ingredient $ingredient, float $qty, float $cost)
    {
        return $this->actingAs($this->owner)->postJson('/api/purchases', [
            'date' => '2026-10-09',
            'expense_category_id' => \App\Models\ExpenseCategory::where('section', 'cost_of_sales')->value('id'),
            'payment_method' => 'cash',
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => $qty, 'total' => round($qty * $cost, 2)]],
        ]);
    }

    // --- Costo promedio ---

    public function test_a_new_ingredient_starts_with_its_cost_as_average_and_an_initial_movement(): void
    {
        $pulpa = $this->createIngredient();

        $this->assertEquals(12.0, (float) $pulpa->weighted_avg_cost);
        $this->assertEquals(50.0, (float) $pulpa->current_stock);
        $movement = InventoryMovement::where('ingredient_id', $pulpa->id)->sole();
        $this->assertSame('initial', $movement->type);
        $this->assertEquals(50.0, (float) $movement->quantity_changed);
    }

    public function test_restock_averages_with_the_initial_cost(): void
    {
        $pulpa = $this->createIngredient();

        $this->restock($pulpa, 10, 24)->assertCreated();

        $pulpa->refresh();
        $this->assertEquals(14.0, (float) $pulpa->weighted_avg_cost, 'CPP = (50×12 + 10×24) ÷ 60');
        $this->assertEquals(60.0, (float) $pulpa->current_stock);
        $this->assertEquals(24.0, (float) $pulpa->unit_cost);
    }

    public function test_restock_on_an_ingredient_loaded_before_the_fix_uses_the_last_cost_as_average(): void
    {
        // Datos antiguos: stock y costo cargados, pero CPP en 0
        $pulpa = Ingredient::create(['name' => 'Pulpa', 'unit' => 'kg', 'current_stock' => 50, 'unit_cost' => 12, 'weighted_avg_cost' => 0]);

        $this->restock($pulpa, 10, 24)->assertCreated();

        $this->assertEquals(14.0, (float) $pulpa->fresh()->weighted_avg_cost);
    }

    public function test_restock_with_negative_stock_sets_the_average_to_the_new_cost(): void
    {
        $granola = Ingredient::create(['name' => 'Granola', 'unit' => 'kg', 'current_stock' => -1.25, 'unit_cost' => 5, 'weighted_avg_cost' => 5]);

        $this->restock($granola, 5, 18)->assertCreated();

        $granola->refresh();
        $this->assertEquals(18.0, (float) $granola->weighted_avg_cost);
        $this->assertEquals(3.75, (float) $granola->current_stock);
    }

    // --- El stock solo cambia por movimientos ---

    public function test_editing_an_ingredient_does_not_change_its_stock(): void
    {
        $pulpa = $this->createIngredient();

        $this->actingAs($this->owner)->putJson("/api/ingredients/{$pulpa->id}", [
            'name' => 'Pulpa de açaí premium', 'unit' => 'kg', 'current_stock' => 999, 'weighted_avg_cost' => 99, 'unit_cost' => 12,
        ])->assertOk();

        $pulpa->refresh();
        $this->assertSame('Pulpa de açaí premium', $pulpa->name);
        $this->assertEquals(50.0, (float) $pulpa->current_stock);
        $this->assertEquals(12.0, (float) $pulpa->weighted_avg_cost);
        $this->assertSame(1, InventoryMovement::where('ingredient_id', $pulpa->id)->count());
    }

    public function test_correcting_the_cost_before_any_purchase_corrects_the_average(): void
    {
        $pulpa = $this->createIngredient();

        $this->actingAs($this->owner)->putJson("/api/ingredients/{$pulpa->id}", ['name' => 'Pulpa', 'unit' => 'kg', 'unit_cost' => 10])->assertOk();
        $this->assertEquals(10.0, (float) $pulpa->fresh()->weighted_avg_cost);

        // Con una compra registrada, el promedio ya lo manejan las compras
        $this->restock($pulpa, 10, 20)->assertCreated();
        $average = (float) $pulpa->fresh()->weighted_avg_cost;
        $this->actingAs($this->owner)->putJson("/api/ingredients/{$pulpa->id}", ['name' => 'Pulpa', 'unit' => 'kg', 'unit_cost' => 5])->assertOk();
        $this->assertEquals($average, (float) $pulpa->fresh()->weighted_avg_cost);
    }

    public function test_a_count_records_the_difference_against_the_stock_of_that_moment(): void
    {
        $pulpa = $this->createIngredient();

        $this->actingAs($this->owner)->postJson('/api/inventory/movements', [
            'ingredient_id' => $pulpa->id, 'type' => 'adjustment', 'counted_stock' => 47.5, 'notes' => 'Conteo del martes',
        ])->assertCreated();

        $this->assertEquals(47.5, (float) $pulpa->fresh()->current_stock);
        $movement = InventoryMovement::where('ingredient_id', $pulpa->id)->where('type', 'adjustment')->sole();
        $this->assertEquals(-2.5, (float) $movement->quantity_changed);
        $this->assertStringContainsString('Conteo del martes', $movement->notes);

        // Si coincide, no se crea movimiento
        $this->actingAs($this->owner)->postJson('/api/inventory/movements', [
            'ingredient_id' => $pulpa->id, 'type' => 'adjustment', 'counted_stock' => 47.5,
        ])->assertCreated();
        $this->assertSame(1, InventoryMovement::where('ingredient_id', $pulpa->id)->where('type', 'adjustment')->count());
    }

    public function test_the_count_is_only_valid_for_adjustments(): void
    {
        $pulpa = $this->createIngredient();

        $this->actingAs($this->owner)->postJson('/api/inventory/movements', [
            'ingredient_id' => $pulpa->id, 'type' => 'waste', 'waste_category' => 'expired', 'counted_stock' => 3,
        ])->assertUnprocessable();
    }

    // --- Validaciones ---

    public function test_a_waste_with_positive_quantity_is_rejected(): void
    {
        $pulpa = $this->createIngredient();

        $this->actingAs($this->owner)->postJson('/api/inventory/movements', [
            'ingredient_id' => $pulpa->id, 'type' => 'waste', 'waste_category' => 'spillage', 'quantity_changed' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity_changed');

        $this->assertEquals(50.0, (float) $pulpa->fresh()->current_stock);

        $this->actingAs($this->owner)->postJson('/api/inventory/movements', [
            'ingredient_id' => $pulpa->id, 'type' => 'waste', 'waste_category' => 'spillage', 'quantity_changed' => -2,
        ])->assertCreated();
        $this->assertEquals(48.0, (float) $pulpa->fresh()->current_stock);
    }

    public function test_a_restock_through_the_movements_endpoint_is_no_longer_accepted(): void
    {
        $pulpa = $this->createIngredient();

        $this->actingAs($this->owner)->postJson('/api/inventory/movements', [
            'ingredient_id' => $pulpa->id, 'type' => 'restock', 'quantity_changed' => 5, 'unit_cost' => 12,
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertEquals(50.0, (float) $pulpa->fresh()->current_stock);
    }

    public function test_a_restock_with_negative_quantity_is_rejected(): void
    {
        $pulpa = $this->createIngredient();

        $this->restock($pulpa, -5, 12)->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_units_are_a_closed_list_but_old_units_can_still_be_edited(): void
    {
        $this->actingAs($this->owner)->postJson('/api/ingredients', ['name' => 'Algo', 'unit' => 'gramos'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit');
        $this->actingAs($this->owner)->postJson('/api/ingredients', ['name' => 'Vasos', 'unit' => 'sacos'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit');

        $legacy = Ingredient::create(['name' => 'Azúcar', 'unit' => 'sacos', 'current_stock' => 2, 'unit_cost' => 100]);
        $this->actingAs($this->owner)->putJson("/api/ingredients/{$legacy->id}", ['name' => 'Azúcar blanca', 'unit' => 'sacos'])->assertOk();
        $this->actingAs($this->owner)->putJson("/api/ingredients/{$legacy->id}", ['name' => 'Azúcar blanca', 'unit' => 'gramos'])->assertUnprocessable();
    }

    // --- Atomicidad ---

    public function test_the_stock_change_is_applied_in_the_database_not_on_a_stale_copy(): void
    {
        $pulpa = $this->createIngredient();
        $stale = Ingredient::find($pulpa->id); // lo que otra venta ya tenía en memoria (50)

        Ingredient::whereKey($pulpa->id)->update(['current_stock' => 47]); // otra venta descontó 3 mientras tanto
        app(InventoryService::class)->applyStockChange($stale->id, -1);

        $this->assertEquals(46.0, (float) $pulpa->fresh()->current_stock, 'una copia vieja no debe pisar el descuento ajeno');
    }

    public function test_the_stock_is_stored_without_floating_point_leftovers(): void
    {
        $granola = Ingredient::create(['name' => 'Granola', 'unit' => 'kg', 'current_stock' => 3.7, 'unit_cost' => 20, 'weighted_avg_cost' => 20]);

        app(InventoryService::class)->applyStockChange($granola->id, -0.025);

        // Valor guardado tal cual en la base: 3.675 y no 3.6750000000000003
        $this->assertSame(3.675, (float) \Illuminate\Support\Facades\DB::table('ingredients')->where('id', $granola->id)->value('current_stock'));
    }

    // --- Ventas ---

    private function syncSale(array $items, ?string $id = null): string
    {
        $id ??= (string) Str::uuid();
        $total = collect($items)->sum('subtotal');
        $this->actingAs($this->owner)->postJson('/api/sales/sync', ['sales' => [[
            'id' => $id, 'subtotal' => $total, 'discount_amount' => 0, 'total_amount' => $total, 'status' => 'completed', 'source' => 'pos',
            'items' => $items,
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ]]])->assertOk()->assertJsonPath('data.synced.0', $id);

        return $id;
    }

    public function test_a_weight_based_sale_deducts_the_kilos_sold_not_one_per_unit(): void
    {
        $helado = Ingredient::create(['name' => 'Helado', 'unit' => 'kg', 'current_stock' => 10, 'unit_cost' => 40, 'weighted_avg_cost' => 40]);
        Recipe::create(['product_id' => $this->p['Autoservicio']->id, 'ingredient_id' => $helado->id, 'quantity_required' => 1]);

        $id = $this->syncSale([['product_id' => $this->p['Autoservicio']->id, 'quantity' => 1, 'unit_price' => 48, 'subtotal' => 48, 'weight_grams' => 400]]);

        $this->assertEquals(9.6, (float) $helado->fresh()->current_stock, '400 g de helado, no 1 kg');

        // Anular devuelve exactamente lo descontado
        $this->actingAs($this->owner)->postJson("/api/sales/{$id}/void", ['void_reason' => 'prueba'])->assertOk();
        $this->assertEquals(10.0, (float) $helado->fresh()->current_stock);
    }

    public function test_a_weight_based_sale_without_saved_grams_deduces_them_from_the_price(): void
    {
        $helado = Ingredient::create(['name' => 'Helado', 'unit' => 'kg', 'current_stock' => 10, 'unit_cost' => 40, 'weighted_avg_cost' => 40]);
        Recipe::create(['product_id' => $this->p['Autoservicio']->id, 'ingredient_id' => $helado->id, 'quantity_required' => 1]);

        // Bs 48 a Bs 0,12 por gramo = 400 g
        $this->syncSale([['product_id' => $this->p['Autoservicio']->id, 'quantity' => 1, 'unit_price' => 48, 'subtotal' => 48]]);

        $this->assertEquals(9.6, (float) $helado->fresh()->current_stock);
    }

    public function test_a_unit_product_still_deducts_per_unit(): void
    {
        $granola = Ingredient::create(['name' => 'Granola', 'unit' => 'kg', 'current_stock' => 2, 'unit_cost' => 20, 'weighted_avg_cost' => 20]);
        Recipe::create(['product_id' => $this->p['Classic Bowl']->id, 'ingredient_id' => $granola->id, 'quantity_required' => 0.025]);

        $this->syncSale([['product_id' => $this->p['Classic Bowl']->id, 'quantity' => 2, 'unit_price' => 28, 'subtotal' => 56]]);

        $this->assertEquals(1.95, (float) $granola->fresh()->current_stock);
    }

    public function test_a_sale_is_saved_even_if_the_stock_is_not_enough(): void
    {
        $granola = Ingredient::create(['name' => 'Granola', 'unit' => 'kg', 'current_stock' => 0.01, 'unit_cost' => 20, 'weighted_avg_cost' => 20]);
        Recipe::create(['product_id' => $this->p['Classic Bowl']->id, 'ingredient_id' => $granola->id, 'quantity_required' => 0.025]);

        $id = $this->syncSale([['product_id' => $this->p['Classic Bowl']->id, 'quantity' => 1, 'unit_price' => 28, 'subtotal' => 28]]);

        $this->assertTrue(\App\Models\Sale::whereKey($id)->exists());
        $this->assertEquals(-0.015, (float) $granola->fresh()->current_stock, 'queda negativo para revisarlo, no se pierde la venta');
    }

    // --- Comando de corrección de datos ---

    public function test_the_fix_command_only_changes_data_with_confirm(): void
    {
        $old = Ingredient::create(['name' => 'Pulpa', 'unit' => 'kg', 'current_stock' => 12, 'unit_cost' => 12, 'weighted_avg_cost' => 0]);
        $noStock = Ingredient::create(['name' => 'Vasos', 'unit' => 'unidades', 'current_stock' => 0, 'unit_cost' => 1, 'weighted_avg_cost' => 0]);
        $ok = Ingredient::create(['name' => 'Granola', 'unit' => 'kg', 'current_stock' => 5, 'unit_cost' => 20, 'weighted_avg_cost' => 18]);

        Artisan::call('inventory:fix-initial-cost');
        $this->assertEquals(0.0, (float) $old->fresh()->weighted_avg_cost, 'sin --confirm no cambia nada');
        $this->assertStringContainsString('Pulpa', Artisan::output());

        Artisan::call('inventory:fix-initial-cost', ['--confirm' => true]);
        $this->assertEquals(12.0, (float) $old->fresh()->weighted_avg_cost);
        $this->assertEquals(0.0, (float) $noStock->fresh()->weighted_avg_cost, 'sin stock no se toca');
        $this->assertEquals(18.0, (float) $ok->fresh()->weighted_avg_cost, 'un CPP existente no se pisa');
    }
}
