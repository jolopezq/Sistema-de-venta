<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Recipe;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReorderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsOhanaCatalog;
use Tests\TestCase;

/**
 * Fase 4 del plan de inventario: consumo real (ventas menos reversos), stock de seguridad, punto de reorden,
 * cantidad sugerida, estados, arranque sin historial, vida útil y vencimientos.
 */
class ReorderTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOhanaCatalog;

    private User $owner;
    private User $admin;
    private User $cashier;
    private int $ref = 900000;

    protected function setUp(): void
    {
        parent::setUp();
        // Martes 15 de septiembre de 2026
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 12, 0, 0, 'America/La_Paz'));
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->buildCatalog();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ingredient(array $attributes = []): Ingredient
    {
        return Ingredient::create(array_merge([
            'name' => 'Insumo ' . Str::random(4), 'unit' => 'kg', 'current_stock' => 0, 'unit_cost' => 10, 'weighted_avg_cost' => 10,
        ], $attributes));
    }

    private function supplier(int $deliveryDays, string $name = 'Distribuidora'): Supplier
    {
        return Supplier::create(['name' => $name, 'delivery_days' => $deliveryDays]);
    }

    /** Consumo de una venta, escrito directamente con la fecha del día (la línea de venta no existe: vale la fecha del movimiento). */
    private function consume(Ingredient $ingredient, string $day, float $quantity, ?int $ref = null): int
    {
        $ref ??= $this->ref++;
        $m = new InventoryMovement();
        $m->forceFill([
            'ingredient_id' => $ingredient->id, 'quantity_changed' => -$quantity, 'type' => 'sale', 'performed_by' => $this->cashier->id,
            'reference_type' => 'sale_item', 'reference_id' => $ref, 'created_at' => "{$day} 12:00:00", 'updated_at' => "{$day} 12:00:00",
        ])->save();

        return $ref;
    }

    /** Un movimiento que anula al de otra venta (anulación o edición): mismo origen, otra fecha. */
    private function reverse(Ingredient $ingredient, string $day, float $quantity, int $ref): void
    {
        $m = new InventoryMovement();
        $m->forceFill([
            'ingredient_id' => $ingredient->id, 'quantity_changed' => $quantity, 'type' => 'adjustment', 'performed_by' => $this->owner->id,
            'reference_type' => 'sale_item', 'reference_id' => $ref, 'created_at' => "{$day} 18:00:00", 'updated_at' => "{$day} 18:00:00",
        ])->save();
    }

    /** Una serie diaria hasta ayer: $values se reparte desde el día más antiguo hasta el 14 de septiembre. */
    private function history(Ingredient $ingredient, array $values): void
    {
        $day = Carbon::create(2026, 9, 14)->subDays(count($values) - 1);
        foreach ($values as $value) {
            $this->consume($ingredient, $day->toDateString(), $value);
            $day->addDay();
        }
    }

    private function item(int $ingredientId, ?array $report = null): array
    {
        $report ??= app(ReorderService::class)->report();

        return collect($report['items'])->firstWhere('id', $ingredientId);
    }

    // --- Fórmulas, con las cifras del ejemplo del plan (§2.3) ---

    public function test_the_formulas_reproduce_the_plan_example_for_the_pulp(): void
    {
        $safety = ReorderService::safetyStock(2.96, 2.59, 2, 2.0, true);
        $this->assertEqualsWithDelta(6.04, $safety, 0.01);

        $point = ReorderService::reorderPoint(2.96 * 2, $safety);
        $this->assertEqualsWithDelta(11.96, $point, 0.01); // «12 kg»

        $suggested = ReorderService::suggestedQuantity(2.96 * 7, $safety, 11.0);
        $this->assertEqualsWithDelta(15.76, $suggested, 0.01);
        $this->assertSame(16.0, ReorderService::toPurchaseUnits($suggested, 1, null)['units']); // «16 bolsas de 1 kg»
    }

    public function test_the_formulas_reproduce_the_plan_example_for_the_granola_and_the_cup(): void
    {
        $safety = ReorderService::safetyStock(0.30, 0.27, 3, 2.0, true);
        $this->assertEqualsWithDelta(0.77, $safety, 0.01);
        $this->assertEqualsWithDelta(1.67, ReorderService::reorderPoint(0.30 * 3, $safety), 0.01); // «1,7 kg»
        $this->assertSame(4.0, ReorderService::toPurchaseUnits(ReorderService::suggestedQuantity(0.30 * 14, $safety, 1.5), 1, null)['units']);

        $cupSafety = ReorderService::safetyStock(6.5, 6.6, 5, 2.0, true);
        $this->assertEqualsWithDelta(24.35, $cupSafety, 0.01);
        $this->assertEqualsWithDelta(56.85, ReorderService::reorderPoint(6.5 * 5, $cupSafety), 0.01); // «57 vasos»
        $boxes = ReorderService::toPurchaseUnits(ReorderService::suggestedQuantity(6.5 * 30, $cupSafety, 50), 50, null);
        $this->assertSame(4.0, $boxes['units']); // «4 cajas de 50»
        $this->assertEquals(200.0, $boxes['quantity']);
    }

    public function test_with_little_data_the_safety_stock_is_the_safety_days_times_the_daily_use(): void
    {
        $this->assertEqualsWithDelta(5.92, ReorderService::safetyStock(2.96, 2.59, 2, 2.0, false), 0.001);
    }

    public function test_purchase_units_round_up_and_respect_the_minimum_order(): void
    {
        $this->assertSame(['units' => 4.0, 'quantity' => 200.0], ReorderService::toPurchaseUnits(169.35, 50, null));
        $this->assertSame(3.0, ReorderService::toPurchaseUnits(3.0000000001, 1, null)['units']);
        $this->assertSame(5.0, ReorderService::toPurchaseUnits(80, 50, 5)['units']);
        $this->assertSame(['units' => 2.0, 'quantity' => 1.0], ReorderService::toPurchaseUnits(0.8, 0.5, null));
        $this->assertSame(0.0, ReorderService::toPurchaseUnits(0.0, 1, null)['units']);
    }

    // --- Consumo diario con datos reales ---

    public function test_daily_use_and_deviation_come_from_the_last_28_days_with_sales(): void
    {
        $supplier = $this->supplier(2);
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 20, 'supplier_id' => $supplier->id, 'target_days' => 7]);
        // 28 días que alternan 1 y 5 kg (media 3) y, antes, un día extraordinario que ya no entra en la ventana
        $this->history($pulp, array_map(fn ($i) => $i % 2 === 0 ? 1 : 5, range(0, 27)));
        $this->consume($pulp, '2026-08-10', 100);
        // Hoy todavía no terminó: no cuenta
        $this->consume($pulp, '2026-09-15', 50);

        $item = $this->item($pulp->id);

        $this->assertSame('real', $item['basis']);
        $this->assertSame(28, $item['days_of_data']);
        $this->assertEqualsWithDelta(3.0, $item['daily_use'], 0.001);
        $sigma = sqrt(28 * 4 / 27);
        $this->assertEqualsWithDelta($sigma, $item['sigma'], 0.001);
        $this->assertEqualsWithDelta(1.65 * $sigma * sqrt(2), $item['safety_stock'], 0.001);
        $this->assertEqualsWithDelta(3.0 * 2 + 1.65 * $sigma * sqrt(2), $item['reorder_point'], 0.001);
        $this->assertEquals(2, $item['lead_days']);
        $this->assertEquals(7, $item['target_days']);
        $this->assertEquals('ok', $item['status']); // 20 kg > punto de reorden de ~10,8
    }

    public function test_the_suggested_quantity_covers_the_target_days_and_rounds_up_to_purchase_units(): void
    {
        $supplier = $this->supplier(2);
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 8, 'supplier_id' => $supplier->id, 'target_days' => 7, 'purchase_unit' => 'bolsa', 'purchase_factor' => 1]);
        $this->history($pulp, array_map(fn ($i) => $i % 2 === 0 ? 1 : 5, range(0, 27)));

        $item = $this->item($pulp->id);

        $safety = 1.65 * sqrt(28 * 4 / 27) * sqrt(2);
        $this->assertSame('reorder', $item['status']);
        $this->assertEquals(ceil(3.0 * 7 + $safety - 8), $item['suggested']['units']);
        $this->assertSame('bolsa', $item['suggested']['purchase_unit']);
        $this->assertEquals($item['suggested']['units'] * 10.0, $item['suggested']['cost']);
    }

    public function test_the_cup_boxes_are_converted_with_the_purchase_factor_and_minimum_order(): void
    {
        $supplier = $this->supplier(5);
        $cup = $this->ingredient(['name' => 'Vaso chico', 'unit' => 'unidades', 'current_stock' => 20, 'supplier_id' => $supplier->id, 'target_days' => 30,
            'purchase_unit' => 'caja', 'purchase_factor' => 50, 'lot_size' => 6, 'unit_cost' => 1, 'weighted_avg_cost' => 1]);
        $this->history($cup, array_fill(0, 28, 6));

        $item = $this->item($cup->id);

        $this->assertSame('critical', $item['status']);
        // 30 días × 6 = 180 − 20 = 160 → 4 cajas, pero el proveedor exige 6
        $this->assertEquals(6.0, $item['suggested']['units']);
        $this->assertEquals(300.0, $item['suggested']['quantity']);
        $this->assertEquals(300.0, $item['suggested']['cost']);
    }

    // --- Los reversos (H19) ---

    public function test_a_voided_sale_is_subtracted_from_the_day_of_the_sale(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 10]);
        foreach (range(1, 4) as $n) {
            $this->consume($pulp, "2026-09-0{$n}", 3);
        }
        $ref = $this->consume($pulp, '2026-09-05', 10);
        // Se anula dos días después: baja el consumo del 5, no el del 7
        $this->reverse($pulp, '2026-09-07', 10, $ref);

        $consumption = app(ReorderService::class)->consumption(Carbon::now(config('app.timezone'))->startOfDay());

        $this->assertSame(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'], $consumption['days']);
        $this->assertEqualsWithDelta(0.0, $consumption['by_ingredient'][$pulp->id]['2026-09-05'], 0.0001);
        $this->assertArrayNotHasKey('2026-09-07', $consumption['by_ingredient'][$pulp->id]);
    }

    public function test_a_count_adjustment_is_not_consumption(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 10]);
        $this->consume($pulp, '2026-09-10', 2);
        $m = new InventoryMovement();
        $m->forceFill(['ingredient_id' => $pulp->id, 'quantity_changed' => -4, 'type' => 'adjustment', 'performed_by' => $this->owner->id,
            'reference_type' => 'inventory_count', 'reference_id' => 1, 'created_at' => '2026-09-11 09:00:00', 'updated_at' => '2026-09-11 09:00:00'])->save();
        $this->consume($pulp, '2026-09-12', 1);

        $consumption = app(ReorderService::class)->consumption(Carbon::now(config('app.timezone'))->startOfDay());

        $this->assertSame(['2026-09-10', '2026-09-12'], $consumption['days']);
        $this->assertEquals([2.0, 1.0], array_values($consumption['by_ingredient'][$pulp->id]));
    }

    public function test_a_real_voided_sale_does_not_count(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 50]);
        Recipe::create(['product_id' => $this->p['Classic Bowl']->id, 'ingredient_id' => $pulp->id, 'quantity_required' => 0.2]);

        $sell = function (string $createdAt) {
            $id = (string) Str::uuid();
            $this->actingAs($this->owner)->postJson('/api/sales/sync', ['sales' => [[
                'id' => $id, 'subtotal' => 28, 'discount_amount' => 0, 'total_amount' => 28, 'status' => 'completed', 'source' => 'pos',
                'created_at' => $createdAt,
                'items' => [['product_id' => $this->p['Classic Bowl']->id, 'quantity' => 1, 'unit_price' => 28, 'subtotal' => 28]],
                'payments' => [['method' => 'cash', 'amount' => 28]],
            ]]])->assertOk();

            return $id;
        };
        $kept = $sell('2026-09-10T15:00:00-04:00');
        $voided = $sell('2026-09-11T15:00:00-04:00');
        $this->actingAs($this->owner)->postJson("/api/sales/{$voided}/void", ['void_reason' => 'error'])->assertOk();

        $consumption = app(ReorderService::class)->consumption(Carbon::now(config('app.timezone'))->startOfDay());

        // Los movimientos se escribieron «hoy» (15), pero el consumo va al día de cada venta
        $this->assertSame(['2026-09-10'], $consumption['days']);
        $this->assertEqualsWithDelta(0.2, $consumption['by_ingredient'][$pulp->id]['2026-09-10'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $consumption['by_ingredient'][$pulp->id]['2026-09-11'] ?? 0.0, 0.0001);
    }

    // --- Por día de la semana ---

    public function test_with_eight_weeks_of_history_the_demand_follows_the_weekdays(): void
    {
        $supplier = $this->supplier(4);
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 100, 'supplier_id' => $supplier->id]);
        // Del 1 de julio al 14 de septiembre: 10 kg los sábados y 2 kg el resto
        $values = [];
        for ($day = Carbon::create(2026, 7, 1); $day->lte(Carbon::create(2026, 9, 14)); $day->addDay()) {
            $value = $day->isSaturday() ? 10 : 2;
            $values[$day->toDateString()] = $value;
            $this->consume($pulp, $day->toDateString(), $value);
        }

        $item = $this->item($pulp->id);

        $this->assertTrue($item['by_weekday']);
        $recent = array_slice(array_values($values), -28);
        $mean = array_sum($recent) / 28;
        $sigma = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $recent)) / 27);
        // Hoy es martes y llega en 4 días: martes, miércoles, jueves y viernes (2 kg cada uno), sin sábado
        $this->assertEqualsWithDelta(8 + 1.65 * $sigma * 2, $item['reorder_point'], 0.001);
        $this->assertEqualsWithDelta($mean, $item['daily_use'], 0.001);
    }

    public function test_with_less_history_the_demand_is_the_plain_average(): void
    {
        $supplier = $this->supplier(4);
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 100, 'supplier_id' => $supplier->id]);
        for ($day = Carbon::create(2026, 8, 18); $day->lte(Carbon::create(2026, 9, 14)); $day->addDay()) {
            $this->consume($pulp, $day->toDateString(), $day->isSaturday() ? 10 : 2);
        }

        $this->assertFalse($this->item($pulp->id)['by_weekday']);
    }

    // --- Arranque sin historial ---

    public function test_without_history_the_owner_estimate_and_safety_days_are_used(): void
    {
        $supplier = $this->supplier(2);
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 10, 'supplier_id' => $supplier->id, 'daily_estimate' => 3, 'safety_days' => 2, 'target_days' => 7]);

        $item = $this->item($pulp->id);

        $this->assertSame('estimate', $item['basis']);
        $this->assertEquals(3.0, $item['daily_use']);
        $this->assertEquals(6.0, $item['safety_stock']); // 2 días × 3 kg
        $this->assertEquals(12.0, $item['reorder_point']); // 3 × 2 + 6
        $this->assertSame('reorder', $item['status']);
        $this->assertEquals(17.0, $item['suggested']['units']); // 21 + 6 − 10
    }

    public function test_the_default_safety_is_two_days_and_the_default_target_is_seven(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 1, 'daily_estimate' => 2]);

        $item = $this->item($pulp->id);

        $this->assertEquals(4.0, $item['safety_stock']);
        $this->assertEquals(7, $item['target_days']);
        $this->assertEquals(1, $item['lead_days']); // sin proveedor, un día
    }

    public function test_with_a_few_days_of_sales_and_no_estimate_the_partial_average_is_used(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 3]);
        $this->history($pulp, [2, 4, 3, 3, 3]);

        $item = $this->item($pulp->id);

        $this->assertSame('partial', $item['basis']);
        $this->assertEqualsWithDelta(3.0, $item['daily_use'], 0.001);
        $this->assertEqualsWithDelta(6.0, $item['safety_stock'], 0.001); // días de seguridad, no el desvío
        $this->assertSame('critical', $item['status']);
    }

    public function test_an_ingredient_that_entered_a_recipe_last_week_is_not_averaged_with_the_weeks_before(): void
    {
        $old = $this->ingredient(['name' => 'Antiguo', 'current_stock' => 50]);
        $recent = $this->ingredient(['name' => 'Nuevo', 'current_stock' => 50]);
        $this->history($old, array_fill(0, 28, 2));
        $this->history($recent, [4, 4, 4, 4, 4]);

        $report = app(ReorderService::class)->report();

        $this->assertSame(28, $this->item($old->id, $report)['days_of_data']);
        $this->assertSame(5, $this->item($recent->id, $report)['days_of_data']);
        $this->assertEqualsWithDelta(4.0, $this->item($recent->id, $report)['daily_use'], 0.001); // no 4 × 5 ÷ 28
        $this->assertSame('partial', $this->item($recent->id, $report)['basis']);
    }

    public function test_the_estimate_wins_over_a_short_history_but_real_data_wins_over_the_estimate(): void
    {
        $short = $this->ingredient(['name' => 'Corto', 'current_stock' => 50, 'daily_estimate' => 9]);
        $this->history($short, [1, 1, 1, 1, 1]);
        $long = $this->ingredient(['name' => 'Largo', 'current_stock' => 50, 'daily_estimate' => 9]);
        $this->history($long, array_fill(0, 28, 1));

        $report = app(ReorderService::class)->report();

        $this->assertSame('estimate', $this->item($short->id, $report)['basis']);
        $this->assertEquals(9.0, $this->item($short->id, $report)['daily_use']);
        $this->assertSame('real', $this->item($long->id, $report)['basis']);
        $this->assertEquals(1.0, $this->item($long->id, $report)['daily_use']);
    }

    public function test_a_manual_reorder_point_alone_works_and_replenishes_up_to_double(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 8, 'minimum_stock' => 12]);

        $item = $this->item($pulp->id);

        $this->assertSame('manual', $item['basis']);
        $this->assertSame('reorder', $item['status']);
        $this->assertEquals(12.0, $item['reorder_point']);
        $this->assertEquals(16.0, $item['suggested']['units']); // hasta 24
    }

    public function test_the_manual_point_is_a_floor_that_never_lowers_the_calculated_one(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 50, 'minimum_stock' => 30, 'daily_estimate' => 1]);
        $other = $this->ingredient(['name' => 'Otra', 'current_stock' => 50, 'minimum_stock' => 2, 'daily_estimate' => 5]);

        $report = app(ReorderService::class)->report();

        $this->assertEquals(30.0, $this->item($pulp->id, $report)['reorder_point']);
        $this->assertSame('ok', $this->item($pulp->id, $report)['status']);
        $this->assertEquals(15.0, $this->item($other->id, $report)['reorder_point']); // 5 × 1 + 2 días × 5
    }

    // --- Estados ---

    public function test_statuses_follow_the_stock_against_the_reorder_point_and_the_lead_time(): void
    {
        $supplier = $this->supplier(2);
        $make = fn (string $name, float $stock) => $this->ingredient(['name' => $name, 'current_stock' => $stock, 'supplier_id' => $supplier->id, 'daily_estimate' => 3, 'safety_days' => 2]);
        // d = 3, L = 2 → se acaba antes de llegar con 6 o menos; punto de reorden 12
        $ok = $make('Ok', 13);
        $reorder = $make('Reordenar', 12);
        $critical = $make('Crítico', 6);
        $out = $make('Agotado', 0);
        $negative = $make('Negativo', -1.5);

        $report = app(ReorderService::class)->report();

        $this->assertSame('ok', $this->item($ok->id, $report)['status']);
        $this->assertSame('reorder', $this->item($reorder->id, $report)['status']);
        $this->assertSame('critical', $this->item($critical->id, $report)['status']);
        $this->assertSame('out', $this->item($out->id, $report)['status']);
        $this->assertSame('out', $this->item($negative->id, $report)['status']);
        $this->assertSame(4, $report['summary']['to_reorder']);
        $this->assertSame(1, $report['summary']['critical']);
        $this->assertSame(2, $report['summary']['out']);
    }

    public function test_an_ingredient_without_consumption_or_manual_point_has_no_data(): void
    {
        $unknown = $this->ingredient(['name' => 'Sin datos', 'current_stock' => 0]);
        $stocked = $this->ingredient(['name' => 'Con stock', 'current_stock' => 4]);

        $report = app(ReorderService::class)->report();

        $this->assertSame('nodata', $this->item($unknown->id, $report)['status']); // nunca se usó: no es un quiebre
        $this->assertSame('nodata', $this->item($stocked->id, $report)['status']);
        $this->assertNull($this->item($unknown->id, $report)['suggested']);
        $this->assertSame(2, $report['summary']['no_data']);
        $this->assertSame(0, $report['summary']['to_reorder']);
    }

    public function test_daily_purchase_and_no_control_do_not_raise_reorder_alerts(): void
    {
        $fruit = $this->ingredient(['name' => 'Banana', 'unit' => 'unidades', 'current_stock' => 2, 'reorder_policy' => 'daily', 'daily_estimate' => 30, 'purchase_unit' => 'unidad', 'purchase_factor' => 1]);
        $skip = $this->ingredient(['name' => 'Servilletas', 'current_stock' => 0, 'reorder_policy' => 'none', 'daily_estimate' => 5]);

        $report = app(ReorderService::class)->report();

        $this->assertSame('daily', $this->item($fruit->id, $report)['status']);
        $this->assertEquals(28.0, $this->item($fruit->id, $report)['suggested']['units']); // lo de un día menos lo que hay
        $this->assertSame('none', $this->item($skip->id, $report)['status']);
        $this->assertNull($this->item($skip->id, $report)['suggested']);
        $this->assertSame(0, $report['summary']['to_reorder']);
        $this->assertSame(1, $report['summary']['daily']);
    }

    public function test_components_without_stock_are_not_listed(): void
    {
        $component = $this->ingredient(['name' => 'Porción base', 'tracks_stock' => false]);
        $real = $this->ingredient(['name' => 'Pulpa', 'daily_estimate' => 1]);

        $ids = collect(app(ReorderService::class)->report()['items'])->pluck('id');

        $this->assertTrue($ids->contains($real->id));
        $this->assertFalse($ids->contains($component->id));
    }

    // --- Perecederos ---

    public function test_a_perishable_is_not_bought_beyond_what_is_consumed_within_its_shelf_life(): void
    {
        // 10 kg al día, 7 días de cobertura (70) pero dura 3 días: como mucho 30 − lo que hay
        $fruit = $this->ingredient(['name' => 'Frutilla', 'current_stock' => 4, 'daily_estimate' => 10, 'target_days' => 7, 'shelf_life_days' => 3, 'safety_days' => 0]);

        $item = $this->item($fruit->id);

        $this->assertSame('critical', $item['status']);
        $this->assertEquals(26.0, $item['suggested']['units']);
        $this->assertTrue($item['suggested']['limited_by_shelf_life']);
    }

    public function test_a_long_shelf_life_does_not_limit_the_purchase(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 2, 'daily_estimate' => 3, 'target_days' => 7, 'shelf_life_days' => 180, 'safety_days' => 0]);

        $item = $this->item($pulp->id);

        $this->assertFalse($item['suggested']['limited_by_shelf_life']);
        $this->assertEquals(19.0, $item['suggested']['units']);
    }

    // --- Vencimientos ---

    public function test_expiry_warns_within_two_days_or_twenty_percent_of_the_shelf_life(): void
    {
        $soon = $this->ingredient(['name' => 'Pronto', 'current_stock' => 1, 'expiration_date' => '2026-09-17']);
        $later = $this->ingredient(['name' => 'Más tarde', 'current_stock' => 1, 'expiration_date' => '2026-09-18']);
        $byShelf = $this->ingredient(['name' => 'Por vida útil', 'current_stock' => 1, 'expiration_date' => '2026-09-20', 'shelf_life_days' => 30]); // 20 % = 6 días
        $expired = $this->ingredient(['name' => 'Vencido', 'current_stock' => 1, 'expiration_date' => '2026-09-14']);
        $empty = $this->ingredient(['name' => 'Sin stock', 'current_stock' => 0, 'expiration_date' => '2026-09-14']);

        $report = app(ReorderService::class)->report();

        $this->assertSame('soon', $this->item($soon->id, $report)['expiry']['level']);
        $this->assertEquals(2, $this->item($soon->id, $report)['expiry']['days_left']);
        $this->assertNull($this->item($later->id, $report)['expiry']['level']);
        $this->assertSame('soon', $this->item($byShelf->id, $report)['expiry']['level']);
        $this->assertSame('expired', $this->item($expired->id, $report)['expiry']['level']);
        $this->assertNull($this->item($empty->id, $report)['expiry']['level']); // sin stock no hay qué vencer
        $this->assertSame(3, $report['summary']['expiring']);
    }

    // --- Endpoints y campos ---

    public function test_the_reorder_endpoints_are_only_for_admins(): void
    {
        $this->actingAs($this->cashier)->getJson('/api/inventory/reorder')->assertForbidden();
        $this->actingAs($this->cashier)->getJson('/api/inventory/reorder/summary')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/inventory/reorder')->assertOk();
        $this->actingAs($this->owner)->getJson('/api/inventory/reorder/summary')->assertOk();
    }

    public function test_the_report_lists_the_supplier_and_the_summary_names_the_urgent_ones(): void
    {
        $supplier = Supplier::create(['name' => 'Distribuidora X', 'delivery_days' => 2, 'phone' => '70000000', 'order_days' => 'Lunes y jueves']);
        $pulp = $this->ingredient(['name' => 'Pulpa', 'current_stock' => 1, 'supplier_id' => $supplier->id, 'daily_estimate' => 3]);
        $this->ingredient(['name' => 'Granola', 'current_stock' => 90, 'daily_estimate' => 1]);

        $full = $this->actingAs($this->admin)->getJson('/api/inventory/reorder')->assertOk();
        $full->assertJsonPath('summary.to_reorder', 1);
        $full->assertJsonPath('items.0.name', 'Pulpa'); // lo urgente primero
        $full->assertJsonPath('items.0.supplier.name', 'Distribuidora X');
        $full->assertJsonPath('items.0.supplier.delivery_days', 2);

        $summary = $this->actingAs($this->admin)->getJson('/api/inventory/reorder/summary')->assertOk();
        $summary->assertJsonPath('to_reorder', 1);
        $summary->assertJsonPath('urgent.0.id', $pulp->id);
        $summary->assertJsonPath('expiring', 0);
        $summary->assertJsonPath('expiring_items', []);
        $this->assertArrayNotHasKey('items', $summary->json());
    }

    public function test_the_new_ingredient_fields_are_saved_and_validated(): void
    {
        $pulp = $this->ingredient(['name' => 'Pulpa']);

        $this->actingAs($this->admin)->putJson("/api/ingredients/{$pulp->id}", [
            'name' => 'Pulpa', 'unit' => 'kg', 'reorder_policy' => 'daily', 'target_days' => 10, 'safety_days' => 1.5,
            'shelf_life_days' => 90, 'daily_estimate' => 2.5, 'minimum_stock' => 8,
        ])->assertOk()
            ->assertJsonPath('data.reorder_policy', 'daily')
            ->assertJsonPath('data.target_days', 10)
            ->assertJsonPath('data.safety_days', 1.5)
            ->assertJsonPath('data.shelf_life_days', 90)
            ->assertJsonPath('data.daily_estimate', 2.5);

        $this->actingAs($this->admin)->putJson("/api/ingredients/{$pulp->id}", [
            'name' => 'Pulpa', 'unit' => 'kg', 'reorder_policy' => 'siempre', 'target_days' => 0, 'safety_days' => -1, 'shelf_life_days' => 0, 'daily_estimate' => -3,
        ])->assertStatus(422)->assertJsonValidationErrors(['reorder_policy', 'target_days', 'safety_days', 'shelf_life_days', 'daily_estimate']);

        // Dejarlos vacíos los borra, salvo la política que siempre tiene un valor
        $this->actingAs($this->admin)->putJson("/api/ingredients/{$pulp->id}", [
            'name' => 'Pulpa', 'unit' => 'kg', 'target_days' => null, 'shelf_life_days' => null, 'daily_estimate' => null,
        ])->assertOk()->assertJsonPath('data.target_days', null)->assertJsonPath('data.reorder_policy', 'daily');
    }

    public function test_an_ingredient_created_without_the_new_fields_controls_by_reorder_point(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/ingredients', ['name' => 'Nuevo', 'unit' => 'kg'])->assertCreated();

        $response->assertJsonPath('data.reorder_policy', 'reorder')->assertJsonPath('data.target_days', null);
    }
}
