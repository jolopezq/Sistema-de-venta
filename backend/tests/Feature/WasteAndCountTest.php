<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\Ingredient;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryMovement;
use App\Models\Recipe;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsOhanaCatalog;
use Tests\TestCase;

/**
 * Fase 3 del plan de inventario: mermas con costo y motivo (también del cajero), conteo físico, historial paginado,
 * reporte de merma registrada y oculta, y su desglose en el punto de equilibrio.
 */
class WasteAndCountTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOhanaCatalog;

    private User $owner;
    private User $admin;
    private User $cashier;
    private User $kitchen;
    private Ingredient $pulpa;
    private Ingredient $granola;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 12, 0, 0, 'America/La_Paz'));
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Rosa']);
        $this->kitchen = User::factory()->create(['role' => 'kitchen']);
        $this->buildCatalog();

        // Compra reciente: el CPP (14) difiere del último costo cargado (24)
        $this->pulpa = Ingredient::create(['name' => 'Pulpa', 'unit' => 'kg', 'current_stock' => 50, 'unit_cost' => 24, 'weighted_avg_cost' => 14]);
        $this->granola = Ingredient::create(['name' => 'Granola', 'unit' => 'kg', 'current_stock' => 10, 'unit_cost' => 5, 'weighted_avg_cost' => 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function waste(User $by, Ingredient $ingredient, float $quantity, string $category = 'spillage', array $extra = [])
    {
        return $this->actingAs($by)->postJson('/api/inventory/waste', array_merge([
            'ingredient_id' => $ingredient->id, 'quantity' => $quantity, 'waste_category' => $category,
        ], $extra));
    }

    private function doCount(array $lines, array $extra = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/inventory/counts', array_merge([
            'lines' => collect($lines)->map(fn ($counted, $name) => ['ingredient_id' => Ingredient::where('name', $name)->value('id'), 'counted' => $counted])->values()->all(),
        ], $extra));
    }

    private function stock(Ingredient $i): float
    {
        return (float) $i->fresh()->current_stock;
    }

    /** Movimiento escrito directamente, con fecha, para armar el historial de un mes. */
    private function movementAt(string $at, array $attributes): InventoryMovement
    {
        $m = new InventoryMovement();
        $m->forceFill(array_merge(['performed_by' => $this->cashier->id, 'reference_type' => 'manual', 'created_at' => $at, 'updated_at' => $at], $attributes))->save();

        return $m;
    }

    private function sell(int $quantity = 1): string
    {
        Recipe::create(['product_id' => $this->p['Classic Bowl']->id, 'ingredient_id' => $this->pulpa->id, 'quantity_required' => 0.2]);
        $id = (string) Str::uuid();
        $this->actingAs($this->owner)->postJson('/api/sales/sync', ['sales' => [[
            'id' => $id, 'subtotal' => 28 * $quantity, 'discount_amount' => 0, 'total_amount' => 28 * $quantity, 'status' => 'completed', 'source' => 'pos',
            'items' => [['product_id' => $this->p['Classic Bowl']->id, 'quantity' => $quantity, 'unit_price' => 28, 'subtotal' => 28 * $quantity]],
            'payments' => [['method' => 'cash', 'amount' => 28 * $quantity]],
        ]]])->assertOk()->assertJsonPath('data.synced.0', $id);

        return $id;
    }

    // --- Merma: el cajero la anota con motivo y queda valorada ---

    public function test_a_cashier_registers_a_waste_valued_at_the_average_cost(): void
    {
        $response = $this->waste($this->cashier, $this->pulpa, 2, 'spillage', ['notes' => 'Se cayó el balde'])->assertCreated();

        $this->assertEquals(48.0, $this->stock($this->pulpa));
        $this->assertNull($response->json('movement.cost'), 'el cajero no ve costos');
        $this->assertEquals(48.0, $response->json('ingredient.current_stock'));
        $this->assertNull($response->json('warning'));

        $movement = InventoryMovement::where('type', 'waste')->sole();
        $this->assertEquals(-2.0, (float) $movement->quantity_changed);
        $this->assertSame('spillage', $movement->waste_category);
        $this->assertSame($this->cashier->id, $movement->performed_by);
        $this->assertEquals(14.0, (float) $movement->unit_cost);
        $this->assertEquals(28.0, (float) $movement->total_cost);
    }

    public function test_the_admin_sees_the_value_of_the_waste_he_registers(): void
    {
        $response = $this->waste($this->admin, $this->pulpa, 2)->assertCreated();

        $this->assertEquals(28.0, $response->json('movement.cost'), 'el costo promedio (14) y no el último costo cargado (24)');
    }

    public function test_every_waste_reason_of_the_plan_is_accepted(): void
    {
        foreach (['expired', 'damaged', 'spillage', 'prep_error', 'sample', 'staff'] as $reason) {
            $this->waste($this->cashier, $this->granola, 0.1, $reason)->assertCreated();
        }
        $this->assertSame(6, InventoryMovement::where('type', 'waste')->count());
        $this->assertEquals(9.4, $this->stock($this->granola));
    }

    public function test_a_waste_needs_a_reason_and_a_positive_quantity(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/inventory/waste', ['ingredient_id' => $this->pulpa->id, 'quantity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('waste_category');
        $this->waste($this->cashier, $this->pulpa, 1, 'robado')->assertUnprocessable()->assertJsonValidationErrors('waste_category');
        $this->waste($this->cashier, $this->pulpa, 0)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->waste($this->cashier, $this->pulpa, -3)->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertEquals(50.0, $this->stock($this->pulpa));
        $this->assertSame(0, InventoryMovement::where('type', 'waste')->count());
    }

    public function test_a_waste_larger_than_the_stock_is_saved_with_a_warning(): void
    {
        $response = $this->waste($this->cashier, $this->granola, 12)->assertCreated();

        $this->assertStringContainsString('más de lo que el sistema tenía', $response->json('warning'));
        $this->assertEquals(-2.0, $this->stock($this->granola));
    }

    public function test_a_waste_without_cost_is_saved_without_value(): void
    {
        $free = Ingredient::create(['name' => 'Agua', 'unit' => 'litros', 'current_stock' => 20, 'unit_cost' => 0, 'weighted_avg_cost' => 0]);

        $response = $this->waste($this->admin, $free, 3)->assertCreated();

        $this->assertNull($response->json('movement.cost'));
        $movement = InventoryMovement::where('ingredient_id', $free->id)->sole();
        $this->assertNull($movement->unit_cost);
        $this->assertNull($movement->total_cost);
    }

    public function test_components_and_deleted_ingredients_cannot_be_wasted(): void
    {
        $component = Ingredient::create(['name' => 'Porción base', 'unit' => 'unidades', 'tracks_stock' => false, 'unit_cost' => 4]);
        $deleted = Ingredient::create(['name' => 'Viejo', 'unit' => 'kg', 'current_stock' => 1, 'unit_cost' => 1]);
        $deleted->delete();

        $this->waste($this->cashier, $component, 1)->assertUnprocessable()->assertJsonValidationErrors('ingredient_id');
        $this->waste($this->cashier, $deleted, 1)->assertUnprocessable()->assertJsonValidationErrors('ingredient_id');
    }

    public function test_the_kitchen_cannot_register_wastes_but_cashier_admin_and_owner_can(): void
    {
        $this->waste($this->kitchen, $this->pulpa, 1)->assertForbidden();
        $this->waste($this->cashier, $this->pulpa, 1)->assertCreated();
        $this->waste($this->admin, $this->pulpa, 1)->assertCreated();
        $this->waste($this->owner, $this->pulpa, 1)->assertCreated();
        $this->assertEquals(47.0, $this->stock($this->pulpa));
    }

    public function test_the_cashier_can_waste_but_cannot_adjust_count_or_read_the_reports(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/inventory/movements', ['ingredient_id' => $this->pulpa->id, 'type' => 'adjustment', 'counted_stock' => 1])->assertForbidden();
        $this->actingAs($this->cashier)->postJson('/api/inventory/counts', ['lines' => [['ingredient_id' => $this->pulpa->id, 'counted' => 1]]])->assertForbidden();
        $this->actingAs($this->cashier)->getJson('/api/inventory/counts')->assertForbidden();
        $this->actingAs($this->cashier)->getJson('/api/inventory/counts/sheet')->assertForbidden();
        $this->actingAs($this->cashier)->getJson('/api/inventory/waste/report')->assertForbidden();
        $this->actingAs($this->cashier)->getJson("/api/ingredients/{$this->pulpa->id}/movements")->assertForbidden();

        $this->assertEquals(50.0, $this->stock($this->pulpa));
    }

    public function test_the_waste_form_lists_reasons_stock_ingredients_and_what_was_registered_today(): void
    {
        Ingredient::create(['name' => 'Porción base', 'unit' => 'unidades', 'tracks_stock' => false, 'unit_cost' => 4]);
        $this->waste($this->cashier, $this->pulpa, 1)->assertCreated();
        $this->waste($this->admin, $this->granola, 0.5, 'expired')->assertCreated();

        $own = $this->actingAs($this->cashier)->getJson('/api/inventory/waste')->assertOk()->json();
        $this->assertCount(6, $own['categories']);
        $this->assertSame(['Granola', 'Pulpa'], array_column($own['ingredients'], 'name'), 'sin componentes de costeo');
        $this->assertCount(1, $own['today'], 'el cajero ve solo lo que anotó él');
        $this->assertSame('Pulpa', $own['today'][0]['ingredient']);

        $all = $this->actingAs($this->admin)->getJson('/api/inventory/waste')->assertOk()->json();
        $this->assertCount(2, $all['today']);
    }

    public function test_the_movements_endpoint_still_takes_wastes_and_uses_the_new_reasons(): void
    {
        $this->actingAs($this->admin)->postJson('/api/inventory/movements', [
            'ingredient_id' => $this->pulpa->id, 'type' => 'waste', 'waste_category' => 'prep_error', 'quantity_changed' => -1,
        ])->assertCreated();

        $movement = InventoryMovement::where('type', 'waste')->sole();
        $this->assertSame('prep_error', $movement->waste_category);
        $this->assertEquals(14.0, (float) $movement->total_cost);
        $this->assertEquals(49.0, $this->stock($this->pulpa));
    }

    // --- Costo de los movimientos de venta ---

    public function test_sale_movements_are_valued_and_the_void_keeps_the_same_value(): void
    {
        $id = $this->sell(2);

        $sale = InventoryMovement::where('type', 'sale')->sole();
        $this->assertEquals(-0.4, (float) $sale->quantity_changed);
        $this->assertEquals(14.0, (float) $sale->unit_cost);
        $this->assertEquals(5.6, (float) $sale->total_cost);

        // El CPP cambia después: el reverso devuelve al valor con que salió
        $this->pulpa->update(['weighted_avg_cost' => 20]);
        $this->actingAs($this->owner)->postJson("/api/sales/{$id}/void", ['void_reason' => 'prueba'])->assertOk();

        $reversal = InventoryMovement::where('type', 'adjustment')->sole();
        $this->assertEquals(0.4, (float) $reversal->quantity_changed);
        $this->assertEquals(14.0, (float) $reversal->unit_cost);
        $this->assertEquals(5.6, (float) $reversal->total_cost);
        $this->assertEquals(50.0, $this->stock($this->pulpa));
    }

    // --- Conteo físico ---

    public function test_a_count_adjusts_every_difference_and_records_the_value(): void
    {
        $response = $this->doCount(['Pulpa' => 47.5, 'Granola' => 10], ['scope' => 'key', 'notes' => 'Martes'])->assertCreated();

        $this->assertEquals(47.5, $this->stock($this->pulpa));
        $this->assertEquals(10.0, $this->stock($this->granola));
        $this->assertSame('key', $response->json('scope'));
        $this->assertSame(2, $response->json('lines_count'));
        $this->assertEquals(-35.0, $response->json('difference_cost'), '−2,5 kg × Bs 14');

        $pulpaLine = collect($response->json('lines'))->firstWhere('name', 'Pulpa');
        $this->assertEquals(50.0, $pulpaLine['expected']);
        $this->assertEquals(47.5, $pulpaLine['counted']);
        $this->assertEquals(-2.5, $pulpaLine['difference']);
        $this->assertEquals(-35.0, $pulpaLine['cost']);

        $movement = InventoryMovement::where('type', 'adjustment')->sole();
        $this->assertSame('inventory_count', $movement->reference_type);
        $this->assertSame($response->json('id'), $movement->reference_id);
        $this->assertEquals(35.0, (float) $movement->total_cost);
        $this->assertStringContainsString('Conteo #' . $response->json('id'), $movement->notes);
        $this->assertStringContainsString('Martes', $movement->notes);
    }

    public function test_a_count_that_matches_leaves_a_line_but_no_movement(): void
    {
        $this->doCount(['Granola' => 10])->assertCreated();

        $this->assertSame(0, InventoryMovement::where('type', 'adjustment')->count());
        $this->assertSame(1, InventoryCountLine::count());
        $this->assertEquals(0.0, (float) InventoryCountLine::sole()->difference);
    }

    public function test_the_difference_is_calculated_against_the_stock_at_the_moment_of_confirming(): void
    {
        $this->sell(5); // el sistema ya no tiene 50 sino 49

        $response = $this->doCount(['Pulpa' => 49])->assertCreated();

        $this->assertEquals(49.0, $response->json('lines.0.expected'));
        $this->assertEquals(0.0, $response->json('lines.0.difference'));
    }

    public function test_the_first_count_of_an_ingredient_is_the_baseline_and_the_second_is_not(): void
    {
        $first = $this->doCount(['Pulpa' => 40])->assertCreated();
        $second = $this->doCount(['Pulpa' => 39, 'Granola' => 9])->assertCreated();

        $this->assertTrue($first->json('lines.0.is_baseline'));
        $lines = collect($second->json('lines'));
        $this->assertFalse($lines->firstWhere('name', 'Pulpa')['is_baseline']);
        $this->assertTrue($lines->firstWhere('name', 'Granola')['is_baseline'], 'primer conteo de la granola');
    }

    public function test_a_count_is_validated(): void
    {
        $component = Ingredient::create(['name' => 'Porción base', 'unit' => 'unidades', 'tracks_stock' => false, 'unit_cost' => 4]);
        $post = fn (array $body) => $this->actingAs($this->admin)->postJson('/api/inventory/counts', $body);

        $post([])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $post(['lines' => []])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $post(['lines' => [['ingredient_id' => $this->pulpa->id, 'counted' => -1]]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.counted');
        $post(['lines' => [['ingredient_id' => $this->pulpa->id]]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.counted');
        $post(['lines' => [['ingredient_id' => $component->id, 'counted' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.ingredient_id');
        $post(['lines' => [['ingredient_id' => 9999, 'counted' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.ingredient_id');
        $post(['scope' => 'todo', 'lines' => [['ingredient_id' => $this->pulpa->id, 'counted' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('scope');
        $post(['lines' => [
            ['ingredient_id' => $this->pulpa->id, 'counted' => 1], ['ingredient_id' => $this->pulpa->id, 'counted' => 2],
        ]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.ingredient_id');

        $this->assertSame(0, InventoryCount::count());
        $this->assertEquals(50.0, $this->stock($this->pulpa));
    }

    public function test_the_count_of_one_ingredient_through_the_old_endpoint_is_a_count_too(): void
    {
        $this->actingAs($this->admin)->postJson('/api/inventory/movements', [
            'ingredient_id' => $this->pulpa->id, 'type' => 'adjustment', 'counted_stock' => 48, 'notes' => 'Revisión',
        ])->assertCreated();

        $count = InventoryCount::sole();
        $this->assertSame('partial', $count->scope);
        $this->assertEquals(-28.0, (float) $count->difference_cost);
        $this->assertEquals(48.0, $this->stock($this->pulpa));

        // Un ajuste sin conteo ya no existe: hay que escribir lo contado
        $this->actingAs($this->admin)->postJson('/api/inventory/movements', [
            'ingredient_id' => $this->pulpa->id, 'type' => 'adjustment', 'quantity_changed' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('counted_stock');
    }

    public function test_the_count_sheet_lists_stock_ingredients_with_cost_key_flag_and_last_count(): void
    {
        Ingredient::create(['name' => 'Porción base', 'unit' => 'unidades', 'tracks_stock' => false, 'unit_cost' => 4]);
        $this->actingAs($this->admin)->putJson("/api/ingredients/{$this->pulpa->id}", ['name' => 'Pulpa', 'unit' => 'kg', 'unit_cost' => 24, 'is_key' => true])->assertOk()->assertJsonPath('data.is_key', true);
        $this->doCount(['Pulpa' => 49])->assertCreated();

        $sheet = collect($this->actingAs($this->admin)->getJson('/api/inventory/counts/sheet')->assertOk()->json('ingredients'));

        $this->assertSame(['Granola', 'Pulpa'], $sheet->pluck('name')->all());
        $pulpa = $sheet->firstWhere('name', 'Pulpa');
        $this->assertTrue($pulpa['is_key']);
        $this->assertEquals(49.0, $pulpa['current_stock']);
        $this->assertEquals(14.0, $pulpa['unit_cost']);
        $this->assertNotNull($pulpa['last_counted_at']);
        $this->assertNull($sheet->firstWhere('name', 'Granola')['last_counted_at']);
        $this->assertFalse($sheet->firstWhere('name', 'Granola')['is_key']);
    }

    public function test_count_history_is_paginated_and_each_count_shows_its_lines(): void
    {
        $this->doCount(['Pulpa' => 49])->assertCreated();
        Carbon::setTestNow(Carbon::now()->addDay());
        $second = $this->doCount(['Pulpa' => 48, 'Granola' => 9.5], ['notes' => 'Segundo'])->assertCreated();

        $list = $this->actingAs($this->admin)->getJson('/api/inventory/counts?per_page=1')->assertOk();
        $this->assertSame(2, $list->json('total'));
        $this->assertCount(1, $list->json('data'));
        $this->assertSame($second->json('id'), $list->json('data.0.id'), 'el más reciente primero');
        $this->assertSame(2, $list->json('data.0.differing_lines'));

        $detail = $this->actingAs($this->admin)->getJson('/api/inventory/counts/' . $second->json('id'))->assertOk();
        $this->assertCount(2, $detail->json('lines'));
        $this->assertSame('Segundo', $detail->json('notes'));
    }

    // --- Historial del insumo ---

    public function test_the_movement_history_is_paginated_newest_first_and_can_be_filtered(): void
    {
        $this->waste($this->cashier, $this->pulpa, 1)->assertCreated();
        $this->waste($this->cashier, $this->pulpa, 2)->assertCreated();
        $this->doCount(['Pulpa' => 40])->assertCreated();

        $page = $this->actingAs($this->admin)->getJson("/api/ingredients/{$this->pulpa->id}/movements?per_page=2")->assertOk();
        $this->assertSame(3, $page->json('total'));
        $this->assertCount(2, $page->json('data'));
        $this->assertSame('adjustment', $page->json('data.0.type'));
        $this->assertSame('Derrame', $this->actingAs($this->admin)->getJson("/api/ingredients/{$this->pulpa->id}/movements?type=waste")->json('data.0.waste_label'));
        $this->assertNotNull($page->json('data.0.total_cost'));
        $this->assertSame('Rosa', $this->actingAs($this->admin)->getJson("/api/ingredients/{$this->pulpa->id}/movements?type=waste")->json('data.0.performed_by_user.name'));

        $wastes = $this->actingAs($this->admin)->getJson("/api/ingredients/{$this->pulpa->id}/movements?type=waste")->assertOk();
        $this->assertSame(2, $wastes->json('total'));
    }

    // --- Reporte del mes ---

    public function test_the_month_report_separates_registered_and_hidden_waste(): void
    {
        // Septiembre: tres mermas anotadas (una de agosto y otra sin costo no cuentan igual)
        $this->movementAt('2026-09-03 10:00:00', ['ingredient_id' => $this->pulpa->id, 'type' => 'waste', 'waste_category' => 'spillage', 'quantity_changed' => -2, 'unit_cost' => 14, 'total_cost' => 28]);
        $this->movementAt('2026-09-09 18:00:00', ['ingredient_id' => $this->granola->id, 'type' => 'waste', 'waste_category' => 'expired', 'quantity_changed' => -1, 'unit_cost' => 5, 'total_cost' => 5, 'performed_by' => $this->admin->id]);
        $this->movementAt('2026-09-10 18:00:00', ['ingredient_id' => $this->granola->id, 'type' => 'waste', 'waste_category' => 'staff', 'quantity_changed' => -0.5]);
        $this->movementAt('2026-08-30 18:00:00', ['ingredient_id' => $this->pulpa->id, 'type' => 'waste', 'waste_category' => 'spillage', 'quantity_changed' => -9, 'unit_cost' => 14, 'total_cost' => 126]);

        // Conteos: el de agosto fija la base de la pulpa; en septiembre falta pulpa y sobra granola
        $august = InventoryCount::create(['scope' => 'key', 'counted_by' => $this->admin->id, 'counted_at' => '2026-08-31 20:00:00']);
        InventoryCountLine::create(['inventory_count_id' => $august->id, 'ingredient_id' => $this->pulpa->id, 'expected' => 50, 'counted' => 45, 'difference' => -5, 'unit_cost' => 14, 'cost' => -70, 'is_baseline' => true]);
        $september = InventoryCount::create(['scope' => 'key', 'counted_by' => $this->admin->id, 'counted_at' => '2026-09-14 20:00:00']);
        InventoryCountLine::create(['inventory_count_id' => $september->id, 'ingredient_id' => $this->pulpa->id, 'expected' => 40, 'counted' => 38, 'difference' => -2, 'unit_cost' => 14, 'cost' => -28, 'is_baseline' => false]);
        InventoryCountLine::create(['inventory_count_id' => $september->id, 'ingredient_id' => $this->granola->id, 'expected' => 8, 'counted' => 8.4, 'difference' => 0.4, 'unit_cost' => 5, 'cost' => 2, 'is_baseline' => false]);

        $report = $this->actingAs($this->owner)->getJson('/api/inventory/waste/report?year=2026&month=9')->assertOk()->json();

        $this->assertEquals(33.0, $report['registered']['total_cost']);
        $this->assertSame(3, $report['registered']['movements']);
        $this->assertSame(1, $report['registered']['unpriced']);
        $this->assertSame(['spillage', 'expired', 'staff'], array_column($report['registered']['by_category'], 'category'));
        $this->assertSame('Derrame', $report['registered']['by_category'][0]['label']);
        $this->assertSame(['Pulpa', 'Granola'], array_column($report['registered']['by_ingredient'], 'name'));
        $this->assertEquals(1.5, $report['registered']['by_ingredient'][1]['quantity']);
        $this->assertEquals(28.0, collect($report['registered']['by_user'])->firstWhere('name', 'Rosa')['cost']);

        // Oculta: −28 de pulpa y +2 de granola = faltante neto de 26; la línea de partida de agosto no cuenta
        $this->assertEquals(26.0, $report['hidden']['net_loss']);
        $this->assertEquals(28.0, $report['hidden']['shortages']);
        $this->assertEquals(2.0, $report['hidden']['surpluses']);
        $this->assertSame(2, $report['hidden']['lines']);
        $this->assertSame('Pulpa', $report['hidden']['by_ingredient'][0]['name'], 'la mayor pérdida primero');
        $this->assertSame(1, $report['counts']['count']);

        // Agosto: la merma anotada y el conteo de partida
        $august = $this->actingAs($this->owner)->getJson('/api/inventory/waste/report?year=2026&month=8')->assertOk()->json();
        $this->assertEquals(126.0, $august['registered']['total_cost']);
        $this->assertEquals(0.0, $august['hidden']['net_loss']);
        $this->assertSame(1, $august['hidden']['baseline_lines']);
    }

    public function test_the_report_defaults_to_the_current_month_and_is_only_for_admins(): void
    {
        $this->waste($this->cashier, $this->pulpa, 1)->assertCreated();

        $report = $this->actingAs($this->admin)->getJson('/api/inventory/waste/report')->assertOk();
        $this->assertSame(9, $report->json('period.month'));
        $this->assertEquals(14.0, $report->json('registered.total_cost'));
        $this->assertSame('Pulpa', $report->json('registered.recent.0.ingredient'));
        $this->assertSame('Rosa', $report->json('registered.recent.0.user'));

        $this->actingAs($this->admin)->getJson('/api/inventory/waste/report?month=13')->assertUnprocessable();
    }

    // --- Punto de equilibrio ---

    public function test_the_break_even_splits_the_gap_between_real_cost_and_recipes(): void
    {
        $this->applyBaseRecipes();
        $sale = \App\Models\Sale::create([
            'id' => Str::uuid()->toString(), 'order_number' => 'T-1', 'cashier_id' => $this->owner->id,
            'subtotal' => 280, 'total_amount' => 280, 'status' => 'completed', 'source' => 'pos',
            'created_at' => '2026-09-05 15:00:00', 'updated_at' => '2026-09-05 15:00:00',
        ]);
        \App\Models\SaleItem::create(['sale_id' => $sale->id, 'product_id' => $this->p['Classic Bowl']->id, 'quantity' => 10, 'unit_price' => 28, 'subtotal' => 280]);
        // Compras del mes: 150 frente a 92 que dicen las recetas
        FinanceExpense::create([
            'date' => '2026-09-04', 'expense_category_id' => ExpenseCategory::where('section', 'cost_of_sales')->value('id'),
            'amount' => 150, 'payment_method' => 'cash', 'user_id' => $this->owner->id,
        ]);
        $this->movementAt('2026-09-10 10:00:00', ['ingredient_id' => $this->pulpa->id, 'type' => 'waste', 'waste_category' => 'spillage', 'quantity_changed' => -1, 'unit_cost' => 14, 'total_cost' => 14]);
        $count = InventoryCount::create(['scope' => 'key', 'counted_by' => $this->admin->id, 'counted_at' => '2026-09-12 20:00:00']);
        InventoryCountLine::create(['inventory_count_id' => $count->id, 'ingredient_id' => $this->pulpa->id, 'expected' => 40, 'counted' => 39, 'difference' => -1, 'unit_cost' => 14, 'cost' => -14, 'is_baseline' => false]);

        $gap = $this->actingAs($this->owner)->getJson('/api/finance/break-even?year=2026&month=9')->assertOk()->json('cost_gap');

        $this->assertEquals(150.0, $gap['real']);
        $this->assertEqualsWithDelta(92.0, $gap['recipes'], 0.01);
        $this->assertEqualsWithDelta(58.0, $gap['difference'], 0.01);
        $this->assertEquals(14.0, $gap['registered_waste']);
        $this->assertEquals(14.0, $gap['hidden_waste']);
        $this->assertEqualsWithDelta(30.0, $gap['other'], 0.01, 'compras que quedan en stock y todo lo que no se explica');
        $this->assertSame(1, $gap['counts']);
        $this->assertSame(1, $gap['hidden_lines']);
    }

    public function test_without_recipes_the_break_even_has_no_cost_gap(): void
    {
        $gap = $this->actingAs($this->owner)->getJson('/api/finance/break-even?year=2026&month=9')->assertOk()->json('cost_gap');

        $this->assertNull($gap);
    }
}
