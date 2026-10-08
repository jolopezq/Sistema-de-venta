<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemOption;
use App\Models\User;
use App\Services\BaseRecipesService;
use App\Services\RecipeCostService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsOhanaCatalog;
use Tests\TestCase;

/**
 * Costo por receta con el modelo por porciones (docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md §2–§3).
 */
class RecipeCostTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOhanaCatalog;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->buildCatalog();
    }

    protected function cost(string $product, array $options = [], ?float $grams = null): array
    {
        $service = app(RecipeCostService::class);
        $service->refresh();
        $opts = [];
        foreach ($options as $name) {
            $opts[$this->o[$name]->id] = ($opts[$this->o[$name]->id] ?? 0) + 1;
        }

        return $service->productCost($this->p[$product], $opts, $grams);
    }

    public function test_preview_classifies_the_catalog_and_proposes_the_document_costs(): void
    {
        $preview = app(BaseRecipesService::class)->preview();
        $kinds = collect($preview['products'])->pluck('kind', 'name');

        $this->assertEquals('bowl', $kinds['Classic Bowl']);
        $this->assertEquals('armable', $kinds['Arma tu Bowl']);
        $this->assertEquals('weight', $kinds['Autoservicio']);
        $this->assertEquals('coffee', $kinds['Capuchino']);

        $components = collect($preview['components'])->keyBy('key');
        $this->assertEquals(4.50, $components['base']['unit_cost']);
        $this->assertEquals('kg', $components['helado_peso']['unit']);
        $this->assertEquals(0.0, $components['cafe']['unit_cost']);

        $options = collect($preview['options'])->keyBy(fn ($o) => "{$o['group']}|{$o['name']}");
        $this->assertEquals('size', $options['Tamaño Acai|Mediano']['kind']);
        $this->assertEquals('without', $options['Toppings|Sin granola']['kind']);
        $this->assertEquals('extra', $options['Toppings|Ositos de gomita']['kind']);
        $this->assertEquals('base_extra', $options['Toppings|Leche condensada']['kind']);
        $this->assertEquals('fruit', $options['Frutas|Kiwi']['kind']);
        $this->assertEquals('coffee_size', $options['Tamaño Cafe|Grande']['kind']);
    }

    public function test_bowl_costs_by_size_and_toppings_match_the_document(): void
    {
        $this->applyBaseRecipes();

        $this->assertEqualsWithDelta(9.20, $this->cost('Classic Bowl')['cost'], 0.001);
        $this->assertEqualsWithDelta(11.70, $this->cost('Classic Bowl', ['Mediano'])['cost'], 0.001);
        $this->assertEqualsWithDelta(14.20, $this->cost('Classic Bowl', ['Grande'])['cost'], 0.001);
        $this->assertEqualsWithDelta(20.20, $this->cost('Patriota', ['Ohana'])['cost'], 0.001);
        $this->assertEqualsWithDelta(9.20 - 0.495, $this->cost('Classic Bowl', ['Sin granola'])['cost'], 0.001);
        $this->assertEqualsWithDelta(10.20, $this->cost('Classic Bowl', ['Ositos de gomita'])['cost'], 0.001);
        $this->assertEqualsWithDelta(9.695, $this->cost('Classic Bowl', ['Extra granola'])['cost'], 0.001);

        // Arma tu Bowl: cada fruta suma 1/3 del mix (3 frutas = un mix completo)
        $this->assertEqualsWithDelta(9.20, $this->cost('Arma tu Bowl', ['Banana', 'Frutilla', 'Kiwi'])['cost'], 0.001);
        $this->assertEqualsWithDelta(7.20 + 2 / 3 * 2, $this->cost('Arma tu Bowl', ['Banana', 'Kiwi'])['cost'], 0.001);
        $this->assertTrue($this->cost('Classic Bowl')['complete']);
    }

    public function test_weight_based_product_is_costed_by_the_kilos_sold(): void
    {
        $this->applyBaseRecipes();

        // 350 g: 0,35 kg × Bs 25 + envase y cucharilla Bs 1,20
        $this->assertEqualsWithDelta(9.95, $this->cost('Autoservicio', [], 350)['cost'], 0.001);
        $this->assertEqualsWithDelta(1.20, $this->cost('Autoservicio', [], 0)['cost'], 0.001);
    }

    public function test_coffee_recipe_is_incomplete_until_its_components_have_a_cost(): void
    {
        $this->applyBaseRecipes();

        $capuchino = $this->cost('Capuchino');
        $this->assertFalse($capuchino['complete']);
        $this->assertContains('Café en grano', $capuchino['missing']);

        $set = fn ($name, $cost) => Ingredient::where('name', $name)->update(['unit_cost' => $cost]);
        $set('Café en grano', 120);      // Bs 120 el kg
        $set('Leche', 8);                // Bs 8 el litro
        $set('Azúcar (sobre)', 0.10);
        $set('Vaso de café + tapa', 0.80);
        $set('Vaso de café grande + tapa', 1.00);

        // 18 g que rinden 2 tazas (9 g = Bs 1,08) + 200 ml (Bs 1,60) + azúcar 0,10 + vaso 0,80
        $capuchino = $this->cost('Capuchino');
        $this->assertTrue($capuchino['complete']);
        $this->assertEqualsWithDelta(3.58, $capuchino['cost'], 0.001);
        // Grande: +100 ml de leche y vaso grande en lugar del normal
        $this->assertEqualsWithDelta(4.58, $this->cost('Capuchino', ['Cafe Grande'])['cost'], 0.001);
    }

    public function test_base_recipes_can_be_applied_again_without_duplicating(): void
    {
        $this->applyBaseRecipes();
        $first = [Ingredient::count(), Recipe::count()];

        $this->applyBaseRecipes(['base' => 5.00]);

        $this->assertEquals($first, [Ingredient::count(), Recipe::count()]);
        $this->assertEquals(5.00, (float) Ingredient::where('name', 'Porción base açaí (bowl chico)')->value('unit_cost'));
        $this->assertEqualsWithDelta(9.70, $this->cost('Classic Bowl')['cost'], 0.001);
    }

    public function test_costing_components_do_not_move_stock_but_real_ingredients_do(): void
    {
        $this->applyBaseRecipes();
        $pulp = Ingredient::create(['name' => 'Pulpa de açaí', 'unit' => 'kg', 'current_stock' => 10, 'unit_cost' => 25]);
        Recipe::create(['product_id' => $this->p['Classic Bowl']->id, 'ingredient_id' => $pulp->id, 'quantity_required' => 0.18]);
        $cashier = User::factory()->create(['role' => 'cashier']);

        $uuid = (string) Str::uuid();
        $this->actingAs($cashier)->postJson('/api/sales/sync', ['sales' => [[
            'id' => $uuid, 'subtotal' => 35, 'discount_amount' => 0, 'total_amount' => 35, 'status' => 'completed', 'source' => 'pos',
            'items' => [['product_id' => $this->p['Classic Bowl']->id, 'quantity' => 1, 'unit_price' => 35, 'subtotal' => 35,
                'modifiers' => [['option_id' => $this->o['Mediano']->id, 'option_name' => 'Mediano', 'quantity' => 1]]]],
            'payments' => [['method' => 'cash', 'amount' => 35]],
        ]]])->assertOk()->assertJsonPath('data.synced.0', $uuid);

        $this->assertEquals(1, InventoryMovement::count()); // solo la pulpa
        $this->assertEqualsWithDelta(9.82, (float) $pulp->fresh()->current_stock, 0.0001);
        $this->assertEquals(0.0, (float) Ingredient::where('name', 'Porción base açaí (bowl chico)')->value('current_stock'));
    }

    public function test_grams_of_weight_based_sales_are_saved(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $uuid = (string) Str::uuid();

        $this->actingAs($cashier)->postJson('/api/sales/sync', ['sales' => [[
            'id' => $uuid, 'subtotal' => 42, 'discount_amount' => 0, 'total_amount' => 42, 'status' => 'completed', 'source' => 'pos',
            'items' => [
                ['product_id' => $this->p['Autoservicio']->id, 'quantity' => 1, 'unit_price' => 42, 'subtotal' => 42, 'weight_grams' => 350],
            ],
            'payments' => [['method' => 'cash', 'amount' => 42]],
        ]]])->assertOk();

        $this->assertEquals(350.0, (float) SaleItem::where('sale_id', $uuid)->value('weight_grams'));
    }

    public function test_grams_of_weight_based_sales_registered_by_the_owner_are_saved(): void
    {
        $at = Carbon::now()->subDay()->setTime(15, 0)->toDateTimeString();
        $res = $this->actingAs($this->owner)->postJson('/api/admin/sales', [
            'created_at' => $at, 'cashier_id' => $this->owner->id, 'subtotal' => 40, 'discount_amount' => 0, 'total_amount' => 40,
            'edit_reason' => 'Venta del talonario de la mañana',
            'items' => [['product_id' => $this->p['Autoservicio']->id, 'quantity' => 1, 'unit_price' => 40, 'subtotal' => 40, 'weight_grams' => 500]],
            'payments' => [['method' => 'cash', 'amount' => 40]],
        ])->assertCreated();

        $this->assertEquals(500.0, (float) SaleItem::where('sale_id', $res->json('sale.id'))->value('weight_grams'));
    }

    protected function sale(string $at, string $source, array $lines): Sale
    {
        $sale = Sale::create([
            'id' => Str::uuid()->toString(), 'order_number' => 'T-' . Str::random(6), 'cashier_id' => $this->owner->id,
            'subtotal' => 0, 'total_amount' => 0, 'status' => 'completed', 'source' => $source, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $total = 0;
        foreach ($lines as [$product, $qty, $unit, $options, $grams]) {
            $item = SaleItem::create([
                'sale_id' => $sale->id, 'product_id' => $this->p[$product]->id, 'quantity' => $qty, 'unit_price' => $unit,
                'subtotal' => $qty * $unit, 'weight_grams' => $grams,
            ]);
            foreach ($options as $name) {
                SaleItemOption::create([
                    'sale_item_id' => $item->id, 'option_id' => $this->o[$name]->id, 'option_group_id' => $this->o[$name]->option_group_id,
                    'option_name_snapshot' => $name, 'additional_price_snapshot' => 0, 'quantity' => 1,
                ]);
            }
            $total += $qty * $unit;
        }
        $sale->update(['subtotal' => $total, 'total_amount' => $total]);

        return $sale;
    }

    public function test_month_cost_by_channel_with_coverage(): void
    {
        $this->applyBaseRecipes();

        $this->sale('2026-09-10 15:00:00', 'pos', [
            ['Classic Bowl', 2, 35, ['Mediano'], null],   // 2 × 11,70
            ['Capuchino', 1, 16, [], null],               // sin costo: no cubierto
        ]);
        // Autoservicio vendido antes de guardar los gramos: 42 ÷ 0,12 = 350 g
        $this->sale('2026-09-11 16:00:00', 'pos', [['Autoservicio', 1, 42, [], null]]);
        $this->sale('2026-09-12 19:00:00', 'pedidosya', [['Patriota', 1, 30, [], null]]);
        $this->sale('2026-10-01 10:00:00', 'pos', [['Classic Bowl', 1, 28, [], null]]); // otro mes

        $month = app(RecipeCostService::class)->monthCost(2026, 9);

        $this->assertEquals(128.0, $month['channels']['local']['revenue']);
        $this->assertEquals(112.0, $month['channels']['local']['covered_revenue']);
        $this->assertEqualsWithDelta(2 * 11.70 + 9.95, $month['channels']['local']['covered_cost'], 0.01);
        $this->assertEquals(30.0, $month['channels']['pedidosya']['revenue']);
        $this->assertEqualsWithDelta(9.20, $month['channels']['pedidosya']['covered_cost'], 0.01);
        $this->assertEqualsWithDelta(142 / 158 * 100, $month['total']['coverage_percent'], 0.1);

        $uncovered = collect($month['uncovered'])->keyBy('name');
        $this->assertEquals('incomplete', $uncovered['Capuchino']['status']);
        // Precio promedio por unidad (sin los productos por peso): (70 + 16 + 30) / 4
        $this->assertEquals(29.0, $month['unit_price']);
    }

    public function test_deleted_product_is_costed_with_the_recipe_of_its_same_name_replacement(): void
    {
        $this->applyBaseRecipes();
        // Un Classic Bowl viejo, sin receta, se vendió y luego se eliminó; el vigente tiene receta
        $old = $this->p['Classic Bowl']->replicate();
        $old->save();
        $this->p['Classic Bowl Viejo'] = $old;
        $this->sale('2026-09-10 15:00:00', 'pos', [['Classic Bowl Viejo', 1, 28, [], null]]);
        $old->delete();
        // Otro eliminado sin reemplazo: sigue sin cubrir y se marca como eliminado
        $gone = $this->p['Classic Bowl']->replicate(['name']);
        $gone->name = 'Bowl de temporada';
        $gone->save();
        $this->p['Bowl de temporada'] = $gone;
        $this->sale('2026-09-11 15:00:00', 'pos', [['Bowl de temporada', 1, 30, [], null]]);
        $gone->delete();

        $month = app(RecipeCostService::class)->monthCost(2026, 9);

        $this->assertEquals(28.0, $month['total']['covered_revenue']);
        $this->assertEqualsWithDelta(9.20, $month['total']['covered_cost'], 0.01);
        $this->assertCount(1, $month['uncovered']);
        $this->assertSame('Bowl de temporada', $month['uncovered'][0]['name']);
        $this->assertTrue($month['uncovered'][0]['deleted']);
    }
}
