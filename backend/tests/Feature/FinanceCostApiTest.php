<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\PedidosYaSettlement;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOhanaCatalog;
use Tests\TestCase;

/**
 * API de Finanzas → Costos y margen.
 */
class FinanceCostApiTest extends TestCase
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

    private function wizard(array $only = []): void
    {
        $preview = $this->actingAs($this->owner)->getJson('/api/finance/costs/base-recipes')->assertOk()->json();
        $costs = collect($preview['components'])->mapWithKeys(fn ($c) => [$c['key'] => $c['unit_cost']])->all();

        $this->actingAs($this->owner)->postJson('/api/finance/costs/base-recipes', [
            'costs'       => $costs,
            'product_ids' => $only ?: array_column($preview['products'], 'product_id'),
            'option_ids'  => array_column($preview['options'], 'option_id'),
        ])->assertOk();
    }

    public function test_costs_overview_shows_margins_by_size_and_channel(): void
    {
        // Semana importada: PedidosYa se quedó 30 % de las ventas brutas
        PedidosYaSettlement::create([
            'start_date' => now()->subDays(20)->toDateString(), 'end_date' => now()->subDays(14)->toDateString(),
            'net_sales' => 100, 'service_fees' => 25, 'total_payout' => 70, 'calculated_payout' => 70,
            'csv_gross_sales' => 100, 'user_id' => $this->owner->id,
        ]);
        $this->wizard();

        $res = $this->actingAs($this->owner)->getJson('/api/finance/costs')->assertOk();
        $this->assertEquals(0.3, $res->json('platform_rate'));

        $classic = collect($res->json('products'))->firstWhere('name', 'Classic Bowl');
        $this->assertEqualsWithDelta(9.20, $classic['cost'], 0.001);
        $this->assertTrue($classic['complete']);
        // Local: (28 − 9,20) / 28 = 67,1 %; PedidosYa: (30 × 0,70 − 9,20) / 30 = 39,3 %
        $this->assertEquals(67.1, $classic['margin_local']);
        $this->assertEquals(39.3, $classic['margin_pya']);

        $mediano = collect($classic['variants'])->firstWhere('name', 'Mediano');
        $this->assertEqualsWithDelta(11.70, $mediano['cost'], 0.001);
        $this->assertEquals(35.0, $mediano['price_local']);
        $this->assertEquals(38.0, $mediano['price_pya']);

        // Autoservicio a 350 g: precio 42, costo 9,95
        $auto = collect($res->json('products'))->firstWhere('name', 'Autoservicio');
        $this->assertEquals(42.0, $auto['price_local']);
        $this->assertEqualsWithDelta(9.95, $auto['cost'], 0.001);

        $capuchino = collect($res->json('products'))->firstWhere('name', 'Capuchino');
        $this->assertFalse($capuchino['complete']);

        $components = collect($res->json('components'))->keyBy('name');
        $this->assertFalse($components['Porción base açaí (bowl chico)']['tracks_stock']);
        $this->assertGreaterThan(0, $components['Porción base açaí (bowl chico)']['used_in']);
    }

    public function test_wizard_skips_products_that_already_have_a_recipe_unless_chosen(): void
    {
        $own = Ingredient::create(['name' => 'Pulpa', 'unit' => 'kg', 'unit_cost' => 30]);
        Recipe::create(['product_id' => $this->p['Patriota']->id, 'ingredient_id' => $own->id, 'quantity_required' => 0.2]);

        $preview = $this->actingAs($this->owner)->getJson('/api/finance/costs/base-recipes')->json();
        $patriota = collect($preview['products'])->firstWhere('name', 'Patriota');
        $this->assertTrue($patriota['has_recipe']);

        // El asistente se aplica solo a Classic Bowl: la receta propia de Patriota queda igual
        $this->wizard([$this->p['Classic Bowl']->id]);
        $this->assertEquals([$own->id], Recipe::where('product_id', $this->p['Patriota']->id)->pluck('ingredient_id')->all());
        $this->assertEquals(4, Recipe::where('product_id', $this->p['Classic Bowl']->id)->count());
    }

    public function test_recipes_and_option_adjustments_can_be_edited(): void
    {
        $this->wizard();
        $coffee = Ingredient::where('name', 'Café en grano')->first();
        $milk = Ingredient::where('name', 'Leche')->first();

        // 18 g que rinden 1 taza (doble), 250 ml de leche
        $this->actingAs($this->owner)->putJson("/api/finance/costs/products/{$this->p['Capuchino']->id}/recipe", ['rows' => [
            ['ingredient_id' => $coffee->id, 'quantity' => 0.018],
            ['ingredient_id' => $milk->id, 'quantity' => 0.25],
        ]])->assertOk();
        $this->assertEquals(2, Recipe::where('product_id', $this->p['Capuchino']->id)->count());

        // Un ajuste de opción puede restar (cantidad negativa) pero no ser 0
        $this->actingAs($this->owner)->putJson("/api/finance/costs/options/{$this->o['Sin granola']->id}/recipe", ['rows' => [
            ['ingredient_id' => Ingredient::where('name', 'like', 'Pack toppings%')->value('id'), 'quantity' => -0.5],
        ]])->assertOk()->assertJsonPath('cost.cost', -0.75);

        $this->actingAs($this->owner)->putJson("/api/finance/costs/options/{$this->o['Sin granola']->id}/recipe", ['rows' => [
            ['ingredient_id' => $coffee->id, 'quantity' => 0],
        ]])->assertStatus(422);

        // En la receta de un producto no se aceptan cantidades negativas
        $this->actingAs($this->owner)->putJson("/api/finance/costs/products/{$this->p['Capuchino']->id}/recipe", ['rows' => [
            ['ingredient_id' => $coffee->id, 'quantity' => -0.01],
        ]])->assertStatus(422);
    }

    public function test_components_can_be_created_and_costed(): void
    {
        $res = $this->actingAs($this->owner)->postJson('/api/finance/costs/components', [
            'name' => 'Sirope de caramelo', 'unit' => 'litros', 'unit_cost' => 45,
        ])->assertCreated();
        $id = $res->json('component.id');
        $this->assertFalse(Ingredient::find($id)->tracks_stock);

        $this->actingAs($this->owner)->postJson('/api/finance/costs/components', [
            'name' => 'sirope  de CARAMELO', 'unit' => 'litros', 'unit_cost' => 40,
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->actingAs($this->owner)->putJson("/api/finance/costs/components/{$id}", ['unit_cost' => 50])->assertOk();
        $this->assertEquals(50.0, (float) Ingredient::find($id)->unit_cost);
    }

    public function test_only_the_owner_manages_costs(): void
    {
        foreach (['cashier', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->getJson('/api/finance/costs')->assertForbidden();
            $this->actingAs($user)->postJson('/api/finance/costs/base-recipes', [])->assertForbidden();
        }
    }

    public function test_pos_catalog_does_not_send_costing_components_so_products_stay_sellable(): void
    {
        $this->wizard();
        $real = Ingredient::create(['name' => 'Pulpa de açaí', 'unit' => 'kg', 'current_stock' => 5, 'unit_cost' => 25]);

        $catalog = $this->actingAs($this->owner)->getJson('/api/catalog')->assertOk()->json();

        $ids = collect($catalog['ingredients'])->pluck('id');
        $this->assertTrue($ids->contains($real->id));
        // Ningún componente de costeo (stock 0) llega al POS: si llegara, el POS daría los bowls por agotados
        $this->assertFalse($ids->intersect(Ingredient::where('tracks_stock', false)->pluck('id'))->isNotEmpty());
        $classic = collect($catalog['products'])->firstWhere('name', 'Classic Bowl');
        $this->assertNotEmpty($classic['recipes']);
    }

    public function test_component_can_use_manual_cost_even_when_it_has_a_composition(): void
    {
        $this->wizard();
        $base = Ingredient::where('name', 'Porción base açaí (bowl chico)')->firstOrFail();
        $pulp = Ingredient::create(['name' => 'Pulpa Real', 'unit' => 'kg', 'current_stock' => 10, 'unit_cost' => 10, 'weighted_avg_cost' => 10]);

        // Guardar composición de 0.18 kg (0.18 * 10 = 1.80 Bs)
        $this->actingAs($this->owner)->putJson("/api/finance/costs/components/{$base->id}/composition", [
            'rows' => [['ingredient_id' => $pulp->id, 'quantity' => 0.18]],
        ])->assertOk();

        // Con use_manual_cost en false: el costo es 1.80
        $res = $this->actingAs($this->owner)->getJson('/api/finance/costs')->assertOk();
        $comp = collect($res->json('components'))->firstWhere('id', $base->id);
        $this->assertEquals(1.80, $comp['cost']);
        $this->assertFalse($comp['use_manual_cost']);

        // Cambiar a use_manual_cost = true con unit_cost = 4.50
        $this->actingAs($this->owner)->putJson("/api/finance/costs/components/{$base->id}", [
            'unit_cost' => 4.50,
            'use_manual_cost' => true,
        ])->assertOk();

        $res = $this->actingAs($this->owner)->getJson('/api/finance/costs')->assertOk();
        $comp = collect($res->json('components'))->firstWhere('id', $base->id);
        $this->assertEquals(4.50, $comp['cost']);
        $this->assertTrue($comp['use_manual_cost']);
        $this->assertEquals(1.80, $comp['composition_cost']);

        // También se puede actualizar use_manual_cost y unit_cost al guardar la composición
        $this->actingAs($this->owner)->putJson("/api/finance/costs/components/{$base->id}/composition", [
            'rows' => [['ingredient_id' => $pulp->id, 'quantity' => 0.18]],
            'use_manual_cost' => false,
        ])->assertOk();
        $this->assertFalse(Ingredient::find($base->id)->use_manual_cost);
    }
}

