<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\BreakEvenService;
use App\Services\InventoryIndicatorsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 5 del plan de inventario: valor del inventario, cobertura, quiebres, % de merma, diferencia de costo y
 * exactitud del conteo, con su estado frente a las metas.
 */
class InventoryIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $cashier;
    private int $ref = 700000;

    protected function setUp(): void
    {
        parent::setUp();
        // Martes 15 de septiembre de 2026
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 12, 0, 0, 'America/La_Paz'));
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ingredient(array $attributes = []): Ingredient
    {
        return Ingredient::create(array_merge([
            'name' => 'Insumo ' . uniqid(), 'unit' => 'kg', 'current_stock' => 0, 'unit_cost' => 10, 'weighted_avg_cost' => 10,
        ], $attributes));
    }

    private function move(Ingredient $ingredient, string $at, string $type, float $quantity, array $extra = []): InventoryMovement
    {
        $m = new InventoryMovement();
        $m->forceFill(array_merge([
            'ingredient_id' => $ingredient->id, 'quantity_changed' => $quantity, 'type' => $type, 'performed_by' => $this->cashier->id,
            'reference_type' => $type === 'sale' ? 'sale_item' : 'manual', 'reference_id' => $type === 'sale' ? $this->ref++ : null,
            'created_at' => $at, 'updated_at' => $at,
        ], $extra))->save();

        return $m;
    }

    private function service(): InventoryIndicatorsService
    {
        return app(InventoryIndicatorsService::class);
    }

    /** Resultado sin tocar finanzas: el cálculo de costo de recetas se prueba aparte con un doble. */
    private function indicators(int $year = 2026, int $month = 9): array
    {
        $this->mock(BreakEvenService::class, fn ($mock) => $mock->shouldReceive('forMonth')->andReturn(['cost_gap' => null, 'revenue' => ['total' => 0]]));

        return app(InventoryIndicatorsService::class)->forMonth($year, $month);
    }

    // --- Metas ---

    public function test_lower_is_better_is_ok_until_the_target_a_warning_until_double_and_an_error_above(): void
    {
        $this->assertSame('ok', InventoryIndicatorsService::lowerIsBetter(5.0, 5.0));
        $this->assertSame('warn', InventoryIndicatorsService::lowerIsBetter(5.1, 5.0));
        $this->assertSame('warn', InventoryIndicatorsService::lowerIsBetter(10.0, 5.0));
        $this->assertSame('err', InventoryIndicatorsService::lowerIsBetter(10.1, 5.0));
        $this->assertNull(InventoryIndicatorsService::lowerIsBetter(null, 5.0));
    }

    // --- Valor del inventario ---

    public function test_the_inventory_value_is_stock_times_the_average_cost(): void
    {
        $this->ingredient(['name' => 'Pulpa', 'current_stock' => 10, 'weighted_avg_cost' => 16.5, 'unit_cost' => 24]);
        $this->ingredient(['name' => 'Granola', 'current_stock' => 4, 'weighted_avg_cost' => 0, 'unit_cost' => 5]); // sin CPP: último costo
        $this->ingredient(['name' => 'Vasos', 'unit' => 'unidades', 'current_stock' => 100, 'weighted_avg_cost' => 0.6, 'unit_cost' => 0.6]);
        $this->ingredient(['name' => 'Sin costo', 'current_stock' => 3, 'weighted_avg_cost' => 0, 'unit_cost' => 0]);
        $this->ingredient(['name' => 'Negativo', 'current_stock' => -2, 'weighted_avg_cost' => 8]);
        $this->ingredient(['name' => 'Agotado', 'current_stock' => 0, 'weighted_avg_cost' => 8]);
        $this->ingredient(['name' => 'Porción base', 'tracks_stock' => false, 'current_stock' => 50, 'weighted_avg_cost' => 99]);

        $value = $this->indicators()['value'];

        $this->assertEquals(165.0 + 20.0 + 60.0, $value['total']);
        $this->assertSame(3, $value['items']);
        $this->assertSame(1, $value['unpriced']);
        $this->assertSame(1, $value['negative']);
        $this->assertSame('Pulpa', $value['top'][0]['name']);
        $this->assertEqualsWithDelta(67.3, $value['top'][0]['share'], 0.05); // 165 ÷ 245
    }

    // --- Cobertura ---

    public function test_the_coverage_lists_the_key_ingredients_by_days_of_stock(): void
    {
        // Con consumo de 3 por día y 1 día de entrega, 3 de stock alcanzan justo para la demora: crítico
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 3, 'daily_estimate' => 3, 'is_key' => true]);
        $granola = $this->ingredient(['name' => 'Granola', 'current_stock' => 30, 'daily_estimate' => 0.5, 'is_key' => true]);
        $this->ingredient(['name' => 'Servilletas', 'current_stock' => 1, 'daily_estimate' => 5]); // no es clave: no entra

        $coverage = $this->indicators()['coverage'];

        $this->assertTrue($coverage['key_only']);
        $this->assertSame(['Pulpa', 'Granola'], array_column($coverage['items'], 'name'));
        $this->assertEquals(1.0, $coverage['items'][0]['days']);
        $this->assertEquals(60.0, $coverage['items'][1]['days']);
        $this->assertEquals(1.0, $coverage['min_days']);
        $this->assertSame('err', $coverage['status']); // la pulpa es crítica
        $this->assertSame(1, $coverage['low']);
    }

    public function test_a_key_ingredient_that_only_needs_reordering_is_a_warning(): void
    {
        // 6 de stock con consumo de 3 por día: 2 días, bajo el punto de reposición (3 + 2 días de seguridad) pero no crítico
        $this->ingredient(['name' => 'Pulpa', 'current_stock' => 6, 'daily_estimate' => 3, 'is_key' => true]);
        $this->ingredient(['name' => 'Granola', 'current_stock' => 30, 'daily_estimate' => 0.5, 'is_key' => true]);

        $coverage = $this->indicators()['coverage'];

        $this->assertSame('warn', $coverage['status']);
        $this->assertEquals(2.0, $coverage['min_days']);
        $this->assertSame(1, $coverage['low']);
    }

    public function test_without_key_ingredients_the_coverage_uses_every_ingredient_with_consumption(): void
    {
        $this->ingredient(['name' => 'Pulpa', 'current_stock' => 100, 'daily_estimate' => 3]);
        $this->ingredient(['name' => 'Sin uso', 'current_stock' => 5]);

        $coverage = $this->indicators()['coverage'];

        $this->assertFalse($coverage['key_only']);
        $this->assertSame(['Pulpa'], array_column($coverage['items'], 'name'));
        $this->assertSame('ok', $coverage['status']);
        $this->assertSame(1, $coverage['without_data']);
    }

    // --- Quiebres ---

    public function test_a_stockout_counts_every_sales_day_until_the_ingredient_is_back(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 19, 'is_key' => true]);
        $granola = $this->ingredient(['name' => 'Granola', 'current_stock' => 100, 'is_key' => true]);

        $this->move($pulp, '2026-09-01 08:00:00', 'initial', 10);
        $this->move($pulp, '2026-09-02 12:00:00', 'sale', -6);
        $this->move($pulp, '2026-09-03 12:00:00', 'sale', -5);     // queda en −1: quiebre
        $this->move($pulp, '2026-09-05 09:00:00', 'restock', 20);  // vuelve a haber el 5
        $this->move($pulp, '2026-09-06 12:00:00', 'sale', -0.0001); // todavía hay
        // La granola se vende todos los días 1 al 14: así hay días de venta, también el 4 y el 5
        $this->move($granola, '2026-09-01 08:00:00', 'initial', 114);
        foreach (range(1, 14) as $d) {
            $this->move($granola, sprintf('2026-09-%02d 13:00:00', $d), 'sale', -1);
        }
        $pulp->update(['current_stock' => 10 - 6 - 5 + 20 - 0.0001]);

        $out = $this->indicators()['stockouts'];

        // 3 (quedó en −1), 4 (siguió sin stock) y 5 (abrió sin stock hasta llegar la compra)
        $this->assertSame(3, $out['days']);
        $this->assertSame('err', $out['status']);
        $this->assertSame('Pulpa', $out['events'][0]['name']);
        $this->assertSame(['2026-09-03', '2026-09-04', '2026-09-05'], $out['events'][0]['dates']);
        $this->assertCount(1, $out['events']); // la granola nunca se acabó
        $this->assertTrue($out['key_only']);
        $this->assertSame(14, $out['operating_days']);
    }

    public function test_a_stockout_day_without_sales_is_not_a_stockout(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 0, 'is_key' => true]);
        $this->move($pulp, '2026-09-01 08:00:00', 'initial', 2);
        $this->move($pulp, '2026-09-02 12:00:00', 'sale', -2);

        $out = $this->indicators()['stockouts'];

        // Solo el 2 es día de venta (y quedó en 0); el local no vendió después
        $this->assertSame(1, $out['days']);
        $this->assertSame('warn', $out['status']);
    }

    public function test_an_ingredient_loaded_in_the_middle_of_the_month_has_no_stockout_before_it_existed(): void
    {
        $granola = $this->ingredient(['name' => 'Granola', 'current_stock' => 100, 'is_key' => true]);
        $this->move($granola, '2026-09-01 08:00:00', 'initial', 114);
        foreach (range(1, 14) as $d) {
            $this->move($granola, sprintf('2026-09-%02d 13:00:00', $d), 'sale', -1);
        }
        // Se cargó el 8 con ventas desde ese mismo día: del 1 al 7 no existía
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 7, 'is_key' => true]);
        $this->move($pulp, '2026-09-08 07:00:00', 'initial', 10);
        $this->move($pulp, '2026-09-08 13:00:00', 'sale', -1);
        $this->move($pulp, '2026-09-09 13:00:00', 'sale', -1);
        $this->move($pulp, '2026-09-10 13:00:00', 'sale', -1);

        $this->assertSame(0, $this->indicators()['stockouts']['days']);
    }

    public function test_an_ingredient_without_sales_in_the_month_or_a_non_key_one_is_not_checked(): void
    {
        $idle = $this->ingredient(['name' => 'Sin ventas', 'current_stock' => 0, 'is_key' => true]);
        $other = $this->ingredient(['name' => 'No clave', 'current_stock' => 0]);
        $this->move($other, '2026-09-02 12:00:00', 'sale', -1);
        $this->move($other, '2026-09-01 08:00:00', 'initial', 1);

        $out = $this->indicators()['stockouts'];

        $this->assertSame(0, $out['days']);
        $this->assertSame('ok', $out['status']);
        $this->assertSame(1, $out['ingredients_checked']); // solo hay un clave
    }

    public function test_the_stockouts_of_a_past_month_use_the_balance_of_that_month(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 50, 'is_key' => true]);
        $this->move($pulp, '2026-08-01 08:00:00', 'initial', 3);
        $this->move($pulp, '2026-08-10 12:00:00', 'sale', -3);   // agosto: queda en 0
        $this->move($pulp, '2026-09-02 09:00:00', 'restock', 50);

        $august = $this->indicators(2026, 8)['stockouts'];

        // Desde el 10 hasta fin de mes siguió sin stock, pero solo el 10 fue día de venta
        $this->assertSame(1, $august['days']);
        $this->assertSame(['2026-08-10'], $august['events'][0]['dates']);
        $this->assertSame(0, $this->indicators(2026, 9)['stockouts']['days']);
        $this->assertSame(1, $this->indicators(2026, 9)['previous']['stockout_days']);
    }

    // --- % de merma ---

    public function test_the_waste_percent_is_the_registered_waste_over_the_consumption_of_the_sales(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 50]);
        $fruit = $this->ingredient(['name' => 'Frutilla', 'type' => 'perecedero', 'current_stock' => 50]);
        $banana = $this->ingredient(['name' => 'Banana', 'type' => 'perecedero', 'current_stock' => 50]);
        // Consumo de ventas: pulpa 10 kg a Bs 20 = 200 (se anuló 1 kg: −20), frutilla 5 kg a Bs 10 = 50, banana 10 kg a Bs 10 = 100
        $this->move($pulp, '2026-09-03 12:00:00', 'sale', -10, ['unit_cost' => 20, 'total_cost' => 200]);
        $this->move($pulp, '2026-09-04 12:00:00', 'adjustment', 1, ['unit_cost' => 20, 'total_cost' => 20, 'reference_type' => 'sale_item', 'reference_id' => 1]);
        $this->move($fruit, '2026-09-03 12:00:00', 'sale', -5, ['unit_cost' => 10, 'total_cost' => 50]);
        $this->move($banana, '2026-09-03 12:00:00', 'sale', -10, ['unit_cost' => 10, 'total_cost' => 100]);
        // Un conteo no es consumo
        $this->move($fruit, '2026-09-05 12:00:00', 'adjustment', -3, ['unit_cost' => 10, 'total_cost' => 30, 'reference_type' => 'inventory_count', 'reference_id' => 1]);
        // Mermas: pulpa Bs 10 (5,6 % de 180), frutilla Bs 12 (24 % de 50) y banana Bs 8 (8 % de 100)
        $this->move($pulp, '2026-09-06 12:00:00', 'waste', -0.5, ['unit_cost' => 20, 'total_cost' => 10, 'waste_category' => 'spillage']);
        $this->move($fruit, '2026-09-06 12:00:00', 'waste', -1.2, ['unit_cost' => 10, 'total_cost' => 12, 'waste_category' => 'expired']);
        $this->move($banana, '2026-09-06 12:00:00', 'waste', -0.8, ['unit_cost' => 10, 'total_cost' => 8, 'waste_category' => 'expired']);

        $waste = $this->indicators()['waste'];

        $this->assertEquals(330.0, $waste['consumption_cost']);
        $this->assertEquals(30.0, $waste['registered_cost']);
        $this->assertEqualsWithDelta(9.1, $waste['percent'], 0.05);
        $this->assertSame('warn', $waste['status']); // 9,1 % > 5 %
        $rows = collect($waste['by_ingredient'])->keyBy('name');
        $this->assertEqualsWithDelta(5.6, $rows['Pulpa']['percent'], 0.05); // 10 ÷ 180
        $this->assertSame('warn', $rows['Pulpa']['status']);
        $this->assertEquals(5.0, $rows['Pulpa']['target']);
        $this->assertEqualsWithDelta(24.0, $rows['Frutilla']['percent'], 0.05);
        $this->assertEquals(10.0, $rows['Frutilla']['target']); // los perecederos tienen 10 %
        $this->assertSame('err', $rows['Frutilla']['status']);
        $this->assertEqualsWithDelta(8.0, $rows['Banana']['percent'], 0.05);
        $this->assertSame('ok', $rows['Banana']['status']);     // 8 % es aceptable en un perecedero, no en la pulpa
        $this->assertSame('Frutilla', $waste['by_ingredient'][0]['name']); // ordenadas por Bs
    }

    public function test_without_sales_the_waste_percent_cannot_be_measured(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 5]);
        $this->move($pulp, '2026-09-06 12:00:00', 'waste', -1, ['unit_cost' => 10, 'total_cost' => 10, 'waste_category' => 'spillage']);

        $waste = $this->indicators()['waste'];

        $this->assertNull($waste['percent']);
        $this->assertNull($waste['status']);
        $this->assertEquals(10.0, $waste['registered_cost']);
        $this->assertSame('err', $waste['by_ingredient'][0]['status']); // se mermó algo que no se vendió
    }

    public function test_the_previous_month_waste_percent_is_reported_for_the_trend(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 50]);
        $this->move($pulp, '2026-08-10 12:00:00', 'sale', -10, ['unit_cost' => 10, 'total_cost' => 100]);
        $this->move($pulp, '2026-08-11 12:00:00', 'waste', -1, ['unit_cost' => 10, 'total_cost' => 10, 'waste_category' => 'spillage']);

        $this->assertEquals(10.0, $this->indicators()['previous']['waste_percent']);
        $this->assertSame('08/2026', $this->indicators()['previous']['label']);
    }

    // --- Merma oculta y exactitud del conteo ---

    private function countLine(InventoryCount $count, Ingredient $ingredient, float $expected, float $counted, bool $baseline = false): void
    {
        InventoryCountLine::create([
            'inventory_count_id' => $count->id, 'ingredient_id' => $ingredient->id, 'expected' => $expected, 'counted' => $counted,
            'difference' => $counted - $expected, 'unit_cost' => 10, 'cost' => round(($counted - $expected) * 10, 2), 'is_baseline' => $baseline,
        ]);
    }

    public function test_the_count_accuracy_is_the_share_of_counted_ingredients_within_two_percent(): void
    {
        $count = InventoryCount::create(['scope' => 'key', 'counted_by' => $this->admin->id, 'counted_at' => '2026-09-08 20:00:00', 'lines_count' => 5, 'difference_cost' => 0]);
        $a = $this->ingredient(['name' => 'A']);
        $b = $this->ingredient(['name' => 'B']);
        $c = $this->ingredient(['name' => 'C']);
        $d = $this->ingredient(['name' => 'D']);
        $e = $this->ingredient(['name' => 'E']);
        $this->countLine($count, $a, 100, 101);            // 1 %: exacto
        $this->countLine($count, $b, 100, 98);             // 2 %: exacto (el límite cuenta)
        $this->countLine($count, $c, 100, 90);             // 10 %: no
        $this->countLine($count, $d, 0, 0);                // esperado 0 y contado 0: exacto
        $this->countLine($count, $e, 50, 50, baseline: true); // primer conteo: no se compara

        $accuracy = $this->indicators()['accuracy'];

        $this->assertSame(4, $accuracy['compared']);
        $this->assertSame(3, $accuracy['exact']);
        $this->assertEquals(75.0, $accuracy['percent']);
        $this->assertSame('warn', $accuracy['status']); // 75 % ≥ 75 pero < 90
        $this->assertSame(1, $accuracy['baseline_lines']);
        $this->assertSame('C', $accuracy['worst'][0]['name']);
        $this->assertEquals(10.0, $accuracy['worst'][0]['gap_percent']);
    }

    public function test_a_month_with_only_first_counts_has_no_accuracy_yet(): void
    {
        $count = InventoryCount::create(['scope' => 'key', 'counted_by' => $this->admin->id, 'counted_at' => '2026-09-08 20:00:00', 'lines_count' => 1, 'difference_cost' => 0]);
        $this->countLine($count, $this->ingredient(['name' => 'A']), 10, 12, baseline: true);

        $accuracy = $this->indicators()['accuracy'];

        $this->assertNull($accuracy['percent']);
        $this->assertNull($accuracy['status']);
        $this->assertSame(1, $accuracy['baseline_lines']);
    }

    public function test_the_hidden_waste_comes_from_the_counts_with_the_previous_month_for_comparison(): void
    {
        $a = $this->ingredient(['name' => 'A']);
        $aug = InventoryCount::create(['scope' => 'key', 'counted_by' => $this->admin->id, 'counted_at' => '2026-08-20 20:00:00', 'lines_count' => 1, 'difference_cost' => 0]);
        $sep = InventoryCount::create(['scope' => 'key', 'counted_by' => $this->admin->id, 'counted_at' => '2026-09-08 20:00:00', 'lines_count' => 1, 'difference_cost' => 0]);
        $this->countLine($aug, $a, 10, 9);   // faltaron 10 Bs
        $this->countLine($sep, $a, 10, 8);   // faltaron 20 Bs

        $hidden = $this->indicators()['hidden'];

        $this->assertEquals(20.0, $hidden['net_loss']);
        $this->assertEquals(10.0, $hidden['previous']);
        $this->assertSame(1, $hidden['counts']);
    }

    // --- Diferencia de costo ---

    public function test_the_cost_gap_is_expressed_in_points_of_the_sales(): void
    {
        $this->mock(BreakEvenService::class, fn ($mock) => $mock->shouldReceive('forMonth')->andReturn([
            'revenue'  => ['total' => 2000.0],
            'cost_gap' => ['real' => 800.0, 'recipes' => 600.0, 'difference' => 200.0, 'registered_waste' => 0, 'hidden_waste' => 0, 'counts' => 0, 'hidden_lines' => 0, 'other' => 200.0],
        ]));

        $gap = app(InventoryIndicatorsService::class)->forMonth(2026, 9)['cost_gap'];

        $this->assertEquals(10.0, $gap['points']);
        $this->assertEquals(40.0, $gap['real_percent']);
        $this->assertEquals(30.0, $gap['recipes_percent']);
        $this->assertSame('warn', $gap['status']); // 10 puntos: más que la meta de 5, no más del doble
    }

    public function test_a_negative_cost_gap_is_judged_by_its_size(): void
    {
        $this->mock(BreakEvenService::class, fn ($mock) => $mock->shouldReceive('forMonth')->andReturn([
            'revenue'  => ['total' => 1000.0],
            'cost_gap' => ['real' => 100.0, 'recipes' => 300.0, 'difference' => -200.0, 'registered_waste' => 0, 'hidden_waste' => 0, 'counts' => 0, 'hidden_lines' => 0, 'other' => -200.0],
        ]));

        $gap = app(InventoryIndicatorsService::class)->forMonth(2026, 9)['cost_gap'];

        $this->assertEquals(-20.0, $gap['points']);
        $this->assertSame('err', $gap['status']);
    }

    public function test_without_recipes_or_sales_there_is_no_cost_gap(): void
    {
        $this->assertNull($this->indicators()['cost_gap']);
    }

    // --- Endpoint ---

    public function test_the_indicators_endpoint_is_only_for_the_owner(): void
    {
        $this->actingAs($this->cashier)->getJson('/api/finance/inventory-indicators')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/finance/inventory-indicators')->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/finance/inventory-indicators?year=2026&month=9')->assertOk()
            ->assertJsonStructure(['period', 'targets', 'value', 'coverage', 'stockouts', 'waste', 'hidden', 'cost_gap', 'accuracy', 'previous'])
            ->assertJsonPath('period.month', 9)
            ->assertJsonPath('targets.accuracy_percent', 90);
    }

    public function test_the_indicators_endpoint_defaults_to_the_current_month_and_validates_the_period(): void
    {
        $this->actingAs($this->owner)->getJson('/api/finance/inventory-indicators')->assertOk()->assertJsonPath('period.year', 2026)->assertJsonPath('period.month', 9);
        $this->actingAs($this->owner)->getJson('/api/finance/inventory-indicators?month=13')->assertStatus(422);
    }
}
