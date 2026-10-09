<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\IngredientComposition;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsOhanaCatalog;
use Tests\TestCase;

/**
 * Composición de componentes de costeo (plan de inventario, F2): una porción («Porción base açaí») descuenta sus
 * insumos reales al vender y se costea por su costo promedio.
 */
class CompositionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOhanaCatalog;

    private User $owner;
    /** @var array<string, Ingredient> */
    private array $i = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->buildCatalog();
        $this->applyBaseRecipes();

        $real = fn (string $name, string $unit, float $stock, float $cost) => $this->i[$name] = Ingredient::create([
            'name' => $name, 'unit' => $unit, 'current_stock' => $stock, 'unit_cost' => $cost, 'weighted_avg_cost' => $cost,
        ]);
        $real('Pulpa', 'kg', 50, 12);
        $real('Helado', 'kg', 20, 40);
        $real('Vaso chico', 'unidades', 100, 0.6);
        $real('Tapa', 'unidades', 100, 0.3);
        $real('Vaso mediano', 'unidades', 100, 0.8);
        $real('Envase', 'unidades', 100, 1);

        $this->compose('Porción base açaí (bowl chico)', ['Pulpa' => 0.18]);
        $this->compose('Kit descartable chico (vaso + tapa + cuchara)', ['Vaso chico' => 1, 'Tapa' => 1]);
        $this->compose('Kit descartable mediano', ['Vaso mediano' => 1, 'Tapa' => 1]);
    }

    private function comp(string $name): Ingredient
    {
        return Ingredient::where('name', $name)->firstOrFail();
    }

    /** @param array<string, float> $rows [nombre del insumo => cantidad por unidad del componente] */
    private function compose(string $component, array $rows)
    {
        return $this->actingAs($this->owner)->putJson("/api/finance/costs/components/{$this->comp($component)->id}/composition", [
            'rows' => collect($rows)->map(fn ($q, $name) => ['ingredient_id' => $this->i[$name]->id, 'quantity' => $q])->values()->all(),
        ]);
    }

    private function stock(string $name): float
    {
        return (float) $this->i[$name]->fresh()->current_stock;
    }

    private function sell(array $items): string
    {
        $id = (string) Str::uuid();
        $total = collect($items)->sum('subtotal');
        $this->actingAs($this->owner)->postJson('/api/sales/sync', ['sales' => [[
            'id' => $id, 'subtotal' => $total, 'discount_amount' => 0, 'total_amount' => $total, 'status' => 'completed', 'source' => 'pos',
            'items' => $items,
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ]]])->assertOk()->assertJsonPath('data.synced.0', $id);

        return $id;
    }

    private function mediumBowl(int $quantity = 1): array
    {
        return ['product_id' => $this->p['Classic Bowl']->id, 'quantity' => $quantity, 'unit_price' => 35, 'subtotal' => 35 * $quantity,
            'modifiers' => [['option_id' => $this->o['Mediano']->id, 'option_name' => 'Mediano', 'quantity' => 1]]];
    }

    public function test_a_medium_bowl_deducts_252_g_of_pulp_and_swaps_the_kit(): void
    {
        $this->sell([$this->mediumBowl()]);

        // Base 1 + 0,4 del tamaño Mediano = 1,4 porciones × 180 g
        $this->assertEquals(49.748, $this->stock('Pulpa'));
        // El kit chico sale (−1) y el tamaño lo reemplaza por el mediano (+1 vaso chico, −1 vaso mediano)
        $this->assertEquals(100.0, $this->stock('Vaso chico'));
        $this->assertEquals(99.0, $this->stock('Vaso mediano'));
        // Una tapa por vaso: sale 1 con el kit chico, vuelve 1 al quitarlo el tamaño y sale 1 con el kit mediano
        $this->assertEquals(99.0, $this->stock('Tapa'));
    }

    public function test_each_movement_names_the_component_it_comes_from(): void
    {
        $this->sell([$this->mediumBowl()]);

        $movement = InventoryMovement::where('ingredient_id', $this->i['Pulpa']->id)->where('type', 'sale')->get();
        $this->assertCount(2, $movement, 'una por la receta del producto y otra por el ajuste de tamaño');
        $this->assertStringContainsString('Porción base açaí', $movement->first()->notes);
        $this->assertEquals(-0.18, (float) $movement->where('reference_type', 'sale_item')->first()->quantity_changed);
        $this->assertEquals(-0.072, (float) $movement->where('reference_type', 'sale_item_option')->first()->quantity_changed);
    }

    public function test_voiding_the_sale_returns_every_expanded_movement(): void
    {
        $id = $this->sell([$this->mediumBowl(2)]);
        $this->assertEquals(49.496, $this->stock('Pulpa'));

        $this->actingAs($this->owner)->postJson("/api/sales/{$id}/void", ['void_reason' => 'prueba'])->assertOk();

        foreach (['Pulpa', 'Vaso chico', 'Vaso mediano', 'Tapa'] as $name) {
            $this->assertEquals((float) $this->i[$name]->current_stock, $this->stock($name), "{$name} vuelve a su stock inicial");
        }
    }

    public function test_changing_the_composition_does_not_change_what_a_past_sale_returns(): void
    {
        $id = $this->sell([$this->mediumBowl()]);

        // El dueño corrige los gramos después de la venta
        $this->compose('Porción base açaí (bowl chico)', ['Pulpa' => 0.25])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/sales/{$id}/void", ['void_reason' => 'prueba'])->assertOk();

        $this->assertEquals(50.0, $this->stock('Pulpa'), 'se devuelve lo que se descontó entonces, no lo que dice la composición de hoy');
    }

    public function test_a_component_without_composition_does_not_move_stock(): void
    {
        $before = InventoryMovement::count();
        $this->sell([['product_id' => $this->p['Classic Bowl']->id, 'quantity' => 1, 'unit_price' => 28, 'subtotal' => 28]]);

        // Receta del bowl: porción base, mix y toppings (sin composición) y kit chico: solo base y kit mueven stock
        $moved = InventoryMovement::where('id', '>', $before)->pluck('ingredient_id')->sort()->values()->all();
        $this->assertEquals(collect([$this->i['Pulpa']->id, $this->i['Vaso chico']->id, $this->i['Tapa']->id])->sort()->values()->all(), $moved);
    }

    public function test_removing_the_composition_turns_it_back_into_a_cost_only_component(): void
    {
        $this->compose('Porción base açaí (bowl chico)', [])->assertOk();

        $this->sell([['product_id' => $this->p['Classic Bowl']->id, 'quantity' => 1, 'unit_price' => 28, 'subtotal' => 28]]);

        $this->assertEquals(50.0, $this->stock('Pulpa'));
        $this->assertEquals(4.5, app(RecipeCostService::class)->ingredient($this->comp('Porción base açaí (bowl chico)')->id)->costPerUnit());
    }

    public function test_a_weight_product_deducts_kilos_from_kg_rows_and_one_per_unit_from_the_rest(): void
    {
        $this->compose('Helado por peso (Autoservicio)', ['Helado' => 1])->assertOk();
        $this->compose('Envase Autoservicio + cucharilla', ['Envase' => 1])->assertOk();

        $this->sell([['product_id' => $this->p['Autoservicio']->id, 'quantity' => 1, 'unit_price' => 48, 'subtotal' => 48, 'weight_grams' => 400]]);

        $this->assertEquals(19.6, $this->stock('Helado'), '400 g de helado');
        $this->assertEquals(99.0, $this->stock('Envase'), 'un envase por venta, no 0,4');
    }

    // --- Costo ---

    public function test_the_cost_of_a_component_follows_the_average_cost_of_its_ingredients(): void
    {
        $costs = app(RecipeCostService::class);
        $costs->refresh();
        // Porción base 0,18 kg × Bs 12 + mix 2 + toppings 1,5 + kit chico (0,6 + 0,3)
        $this->assertEquals(round(0.18 * 12 + 2 + 1.5 + 0.9, 4), $costs->productCost($this->p['Classic Bowl'])['cost']);

        $this->i['Pulpa']->update(['weighted_avg_cost' => 20]);
        $costs->refresh();
        $this->assertEquals(round(0.18 * 20 + 2 + 1.5 + 0.9, 4), $costs->productCost($this->p['Classic Bowl'])['cost'], 'cambia cuando cambia el CPP');
    }

    public function test_an_ingredient_without_cost_leaves_the_recipe_incomplete(): void
    {
        $this->i['Tapa']->update(['unit_cost' => 0, 'weighted_avg_cost' => 0]);
        $costs = app(RecipeCostService::class);
        $costs->refresh();

        $result = $costs->productCost($this->p['Classic Bowl']);

        $this->assertFalse($result['complete']);
        $this->assertContains('Kit descartable chico (vaso + tapa + cuchara)', $result['missing']);
    }

    public function test_the_costs_screen_shows_the_composition_and_the_calculated_cost(): void
    {
        $data = $this->actingAs($this->owner)->getJson('/api/finance/costs')->assertOk()->json();
        $base = collect($data['components'])->firstWhere('name', 'Porción base açaí (bowl chico)');

        $this->assertSame([['ingredient_id' => $this->i['Pulpa']->id, 'quantity' => 0.18]], $base['composition']);
        $this->assertEquals(2.16, $base['cost']);
        $this->assertTrue($base['composition_complete']);

        $mix = collect($data['components'])->firstWhere('name', 'Mix de frutas (2–3 frutas)');
        $this->assertSame([], $mix['composition']);
        $this->assertEquals(2.0, $mix['cost'], 'sin composición conserva su costo cargado');
    }

    // --- Endpoint de composición ---

    public function test_only_cost_components_can_have_a_composition_made_of_real_ingredients(): void
    {
        // Un insumo con stock no tiene composición
        $this->actingAs($this->owner)->putJson("/api/finance/costs/components/{$this->i['Pulpa']->id}/composition", ['rows' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('ingredient');

        // El destino no puede ser otro componente (un solo nivel)
        $other = $this->comp('Mix de frutas (2–3 frutas)');
        $this->actingAs($this->owner)->putJson("/api/finance/costs/components/{$this->comp('Porción base açaí (bowl chico)')->id}/composition", [
            'rows' => [['ingredient_id' => $other->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('rows.0.ingredient_id');

        $this->actingAs($this->owner)->putJson("/api/finance/costs/components/{$this->comp('Porción base açaí (bowl chico)')->id}/composition", [
            'rows' => [['ingredient_id' => $this->i['Pulpa']->id, 'quantity' => 0]],
        ])->assertUnprocessable()->assertJsonValidationErrors('rows.0.quantity');
    }

    public function test_the_same_ingredient_twice_is_added_up_and_replaces_the_previous_composition(): void
    {
        $component = $this->comp('Porción base açaí (bowl chico)');
        $this->actingAs($this->owner)->putJson("/api/finance/costs/components/{$component->id}/composition", ['rows' => [
            ['ingredient_id' => $this->i['Pulpa']->id, 'quantity' => 0.1],
            ['ingredient_id' => $this->i['Pulpa']->id, 'quantity' => 0.08],
            ['ingredient_id' => $this->i['Helado']->id, 'quantity' => 0.02],
        ]])->assertOk()->assertJsonPath('component.cost', 2.96); // 0,18 kg × 12 + 0,02 kg × 40

        $this->assertSame(2, IngredientComposition::where('component_id', $component->id)->count());
        $this->assertEquals(0.18, (float) IngredientComposition::where('component_id', $component->id)->where('ingredient_id', $this->i['Pulpa']->id)->value('quantity'));
    }

    public function test_only_the_owner_can_edit_a_composition(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson("/api/finance/costs/components/{$this->comp('Porción base açaí (bowl chico)')->id}/composition", ['rows' => []])
            ->assertForbidden();
    }

    public function test_an_ingredient_that_is_part_of_a_composition_cannot_be_deleted(): void
    {
        $this->i['Pulpa']->update(['current_stock' => 0]);

        $this->actingAs($this->owner)->deleteJson("/api/ingredients/{$this->i['Pulpa']->id}")
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'composición'));
        $this->assertNotNull(Ingredient::find($this->i['Pulpa']->id));
    }

    // --- Catálogo del POS ---

    public function test_the_catalog_sends_the_recipe_already_expanded(): void
    {
        $catalog = $this->actingAs($this->owner)->getJson('/api/catalog')->assertOk()->json();
        $bowl = collect($catalog['products'])->firstWhere('name', 'Classic Bowl');

        $pulp = collect($bowl['stock_recipe'])->firstWhere('ingredient_id', $this->i['Pulpa']->id);
        $this->assertEquals(0.18, $pulp['quantity']);
        $this->assertFalse($pulp['per_kg'], 'la porción base está en unidades: no se multiplica por los kilos');
        $this->assertNotNull(collect($bowl['stock_recipe'])->firstWhere('ingredient_id', $this->i['Vaso chico']->id));

        $medium = collect($bowl['option_groups'])->flatMap(fn ($g) => $g['options'])->firstWhere('name', 'Mediano');
        $byId = collect($medium['stock_recipe'])->keyBy('ingredient_id');
        $this->assertEquals(0.072, $byId[$this->i['Pulpa']->id]['quantity']);
        $this->assertEquals(-1.0, $byId[$this->i['Vaso chico']->id]['quantity']);
        $this->assertEquals(1.0, $byId[$this->i['Vaso mediano']->id]['quantity']);
        $this->assertArrayNotHasKey($this->i['Tapa']->id, $byId->all(), 'la tapa sale del kit chico y entra en el mediano: no cambia');

        $this->assertNotContains($this->comp('Porción base açaí (bowl chico)')->id, array_column($catalog['ingredients'], 'id'), 'los componentes no se envían como insumos con stock');
    }

    public function test_the_catalog_marks_weight_rows_per_kilo(): void
    {
        $this->compose('Helado por peso (Autoservicio)', ['Helado' => 1])->assertOk();
        $this->compose('Envase Autoservicio + cucharilla', ['Envase' => 1])->assertOk();

        $catalog = $this->actingAs($this->owner)->getJson('/api/catalog')->assertOk()->json();
        $rows = collect(collect($catalog['products'])->firstWhere('name', 'Autoservicio')['stock_recipe'])->keyBy('ingredient_id');

        $this->assertTrue($rows[$this->i['Helado']->id]['per_kg']);
        $this->assertFalse($rows[$this->i['Envase']->id]['per_kg']);
    }
}
