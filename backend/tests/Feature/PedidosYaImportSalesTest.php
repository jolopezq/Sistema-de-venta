<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\PedidosYaOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\PedidosYa\ItemsParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La importación semanal crea o vincula las ventas PedidosYa (plan de Finanzas §6.2–6.4 y tests 3–3e;
 * auditoría A4). Catálogo armado con los precios PedidosYa deducidos de la semana 14–20/09.
 */
class PedidosYaImportSalesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private string $csv;
    private array $p = [];
    private array $o = [];

    private array $statement = [
        'payout_date'       => '2026-09-24',
        'net_sales'         => 710.00,
        'service_fees'      => 187.00,
        'advertising'       => 112.65,
        'refunds'           => 153.04,
        'taxes'             => 39.83,
        'total_payout'      => 523.56,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->csv = base_path('tests/Fixtures/semana_pedidosya_anonimizada.csv');

        $cat = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        foreach (['Classic Bowl' => 30, 'Patriota' => 30, 'Arma tu Bowl' => 32] as $name => $pya) {
            $this->p[$name] = Product::create([
                'name' => $name, 'price' => 28, 'delivery_price' => $pya, 'category_id' => $cat->id, 'printer_target' => 'kitchen',
            ]);
        }

        $size = OptionGroup::create(['name' => 'Tamaño Acai', 'min_selections' => 0, 'max_selections' => 1, 'pedidosya_pricing' => true]);
        $fruits = OptionGroup::create(['name' => 'Frutas', 'min_selections' => 0, 'max_selections' => 3, 'pedidosya_pricing' => false]);
        $toppings = OptionGroup::create(['name' => 'Toppings', 'min_selections' => 0, 'max_selections' => 10, 'pedidosya_pricing' => false]);

        foreach ([
            [$size, 'Mediano', 7, 8], [$size, 'Grande', 14, 16], [$size, 'Ohana', 32, 36],
            [$fruits, 'Banana', 0, 0], [$fruits, 'Frutilla', 0, 0], [$fruits, 'Piña', 0, 0], [$fruits, 'Kiwi', 0, 0], [$fruits, 'Durazno', 0, 0],
            [$toppings, 'Sin granola', 0, 0], [$toppings, 'Sin leche en polvo', 0, 0], [$toppings, 'Chispas de corazones', 4, 4], [$toppings, 'Leche condensada', 4, 0],
        ] as [$group, $name, $price, $pya]) {
            $this->o[$name] = Option::create(['option_group_id' => $group->id, 'name' => $name, 'additional_price' => $price, 'delivery_price' => $pya]);
        }

        foreach ($this->p as $product) {
            $product->optionGroups()->attach([$size->id, $toppings->id]);
        }
        $this->p['Arma tu Bowl']->optionGroups()->attach($fruits->id);
    }

    private function mapNames(): void
    {
        $this->actingAs($this->owner)->postJson('/api/finance/pedidosya-mappings', ['mappings' => [
            ['entity_type' => 'product', 'name' => 'Patriota Bowl', 'internal_id' => $this->p['Patriota']->id],
            ['entity_type' => 'product', 'name' => 'Arma Tu Ohana Acai', 'internal_id' => $this->p['Arma tu Bowl']->id],
            ['entity_type' => 'option', 'name' => 'Extra de leche condensada', 'internal_id' => $this->o['Leche condensada']->id],
            ['entity_type' => 'option', 'name' => 'Sin leche condensada', 'internal_id' => 0],
            ['entity_type' => 'option', 'name' => 'Syrup de chocolate', 'internal_id' => 0],
        ]])->assertCreated();
    }

    private function import(array $extra = [])
    {
        return $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements', array_merge($this->statement, [
            'csv_file' => new UploadedFile($this->csv, 'semana.csv', 'text/csv', null, true),
        ], $extra), ['Accept' => 'application/json']);
    }

    private function preview()
    {
        return $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements/preview', [
            'csv_file' => new UploadedFile($this->csv, 'semana.csv', 'text/csv', null, true),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    private function posSale(string $at, float $total, ?string $notes = null): Sale
    {
        return Sale::create([
            'id' => Str::uuid()->toString(), 'order_number' => 'POS-' . Str::random(5), 'cashier_id' => $this->owner->id,
            'subtotal' => $total, 'total_amount' => $total, 'status' => 'completed', 'source' => 'pedidosya',
            'notes' => $notes, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function test_parser_splits_items_and_options(): void
    {
        $items = (new ItemsParser())->parse('4 Arma Tu Ohana Acai [1 Durazno, 1 Frutilla], 1 Arma Tu Ohana Acai [1 Banana, 1 Durazno], 2 Classic Bowl');

        $this->assertCount(3, $items);
        $this->assertSame(4, $items[0]['quantity']);
        $this->assertSame('Arma Tu Ohana Acai', $items[0]['name']);
        $this->assertSame([['quantity' => 1, 'name' => 'Durazno'], ['quantity' => 1, 'name' => 'Frutilla']], $items[0]['options']);
        $this->assertSame(['quantity' => 2, 'name' => 'Classic Bowl', 'options' => []], $items[2]);
    }

    public function test_unresolved_names_block_the_import_and_are_listed_with_suggestions(): void
    {
        $preview = $this->preview();
        $products = collect($preview->json('sales.unresolved.products'))->keyBy('name');
        $options = collect($preview->json('sales.unresolved.options'))->pluck('name');

        $this->assertEqualsCanonicalizing(['Patriota Bowl', 'Arma Tu Ohana Acai'], $products->keys()->all());
        $this->assertEquals('Patriota', $products['Patriota Bowl']['suggestions'][0]['name']);
        // Las opciones se evalúan cuando su producto tiene equivalencia
        $this->assertEquals([], $options->all());

        $this->actingAs($this->owner)->postJson('/api/finance/pedidosya-mappings', ['mappings' => [
            ['entity_type' => 'product', 'name' => 'Patriota Bowl', 'internal_id' => $this->p['Patriota']->id],
            ['entity_type' => 'product', 'name' => 'Arma Tu Ohana Acai', 'internal_id' => $this->p['Arma tu Bowl']->id],
        ]])->assertCreated();
        $options = collect($this->preview()->json('sales.unresolved.options'))->keyBy('name');
        $this->assertEqualsCanonicalizing(['Sin leche condensada', 'Syrup de chocolate', 'Extra de leche condensada'], $options->keys()->all());
        $this->assertContains('Leche condensada', collect($options['Extra de leche condensada']['choices'])->pluck('name')->all());

        $this->import()->assertStatus(422)->assertJsonValidationErrors('mappings');
        $this->assertDatabaseCount('pedidosya_settlements', 0);
        $this->assertEquals(0, Sale::count());

        // Se pueden cargar solo los montos y crear las ventas después
        $this->import(['sales_mode' => 'skip'])->assertCreated();
        $this->assertEquals(0, Sale::count());
    }

    public function test_import_creates_one_sale_per_delivered_order_with_its_date_items_and_amount(): void
    {
        $this->mapNames();
        $res = $this->import()->assertCreated();

        $res->assertJsonPath('sales.created', 11)->assertJsonPath('sales.cancelled', 2)->assertJsonPath('sales.errors', []);
        $this->assertEquals(11, Sale::where('source', 'pedidosya')->count());

        // 2290007611: 19/09 16:15, Bs 160, "4 Arma Tu [Durazno, Frutilla], 1 Arma Tu [Banana, Durazno]" → 2 líneas
        $order = PedidosYaOrder::where('order_number', '2290007611')->first();
        $this->assertEquals('created', $order->sale_link);
        $sale = Sale::with(['items.saleItemOptions', 'payments'])->find($order->sale_id);

        $this->assertEquals('2026-09-19 16:15:00', $sale->created_at->format('Y-m-d H:i:s'));
        $this->assertEquals(160.0, (float) $sale->total_amount);
        $this->assertEquals('pedidosya', $sale->source);
        $this->assertEquals('delivered', $sale->preparation_status);
        $this->assertStringContainsString('PedidosYa #2290007611', $sale->notes);
        $this->assertCount(2, $sale->items);
        $this->assertEquals([4, 1], $sale->items->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertEquals(['Durazno', 'Frutilla'], $sale->items[0]->saleItemOptions->pluck('option_name_snapshot')->all());
        $this->assertEquals(32.0, (float) $sale->items[0]->unit_price);
        $this->assertEquals([['delivery_platform', 160.0]], $sale->payments->map(fn ($p) => [$p->method, (float) $p->amount])->all());

        // Las ventas suman exactamente el "Total parcial" de cada pedido entregado (748)
        $this->assertEquals(748.0, round((float) Sale::where('source', 'pedidosya')->sum('total_amount'), 2));

        // Los cancelados no crean venta
        $this->assertNull(PedidosYaOrder::where('order_number', '2291616543')->value('sale_id'));
    }

    public function test_price_difference_with_the_tariff_is_kept_and_audited(): void
    {
        $this->mapNames();
        $this->import()->assertCreated();

        // 2287639518: Arma Tu (32) + Grande (16) + Leche condensada (4) = 52 en el Tarifario; PedidosYa cobró 56
        $order = PedidosYaOrder::where('order_number', '2287639518')->first();
        $this->assertEquals(52.0, (float) $order->catalog_total);
        $sale = Sale::with('items')->find($order->sale_id);
        $this->assertEquals(56.0, (float) $sale->total_amount);
        $this->assertEquals('Precio PedidosYa (importado)', $sale->items[0]->price_override_reason);
        $this->assertStringContainsString('Syrup de chocolate', $sale->notes);
        $this->assertTrue(AuditLog::where('action', 'pedidosya_price_override')->where('description', 'like', "%{$sale->order_number}%")->exists());

        $report = $this->actingAs($this->owner)->getJson("/api/finance/pedidosya-settlements/{$order->settlement_id}/report")->assertOk();
        $diff = collect($report->json('control.price_differences'))->firstWhere('order_number', '2287639518');
        $this->assertEquals(4.0, $diff['difference']);
    }

    public function test_manual_pos_sales_are_linked_not_duplicated(): void
    {
        $this->mapNames();
        // Misma hora (±30 min) y monto que 2285990545 (16/09 19:10, Bs 38)
        $byTime = $this->posSale('2026-09-16 19:25:00', 38);
        // Nº de pedido anotado en el cobro (formato del POS) para 2290007611
        $byNote = $this->posSale('2026-09-19 18:40:00', 160, 'PedidosYa #2290007611');
        // Otra venta PedidosYa sin relación (monto distinto)
        $this->posSale('2026-09-16 19:20:00', 99);

        $preview = $this->preview();
        $rows = collect($preview->json('sales.orders'))->keyBy('order_number');
        $this->assertEquals('link', $rows['2285990545']['action']);
        $this->assertEquals($byTime->id, $rows['2285990545']['sale']['id']);
        $this->assertEquals('link', $rows['2290007611']['action']);
        $this->assertEquals(9, $preview->json('sales.summary.create'));

        $this->import()->assertCreated()->assertJsonPath('sales.created', 9)->assertJsonPath('sales.linked', 2);

        $this->assertEquals(12, Sale::where('source', 'pedidosya')->count()); // 9 creadas + 3 del POS
        $this->assertEquals($byTime->id, PedidosYaOrder::where('order_number', '2285990545')->value('sale_id'));
        $this->assertEquals('linked', PedidosYaOrder::where('order_number', '2290007611')->value('sale_link'));
    }

    public function test_owner_can_choose_to_create_instead_of_linking(): void
    {
        $this->mapNames();
        $this->posSale('2026-09-16 19:25:00', 38);

        $this->import(['links' => ['2285990545' => 'new']])->assertCreated()->assertJsonPath('sales.created', 11);
        $this->assertEquals(12, Sale::where('source', 'pedidosya')->count());
    }

    public function test_reimporting_the_week_does_not_duplicate_sales(): void
    {
        $this->mapNames();
        $this->import()->assertCreated();
        $this->import()->assertCreated()->assertJsonPath('sales.created', 0)->assertJsonPath('sales.already', 11);

        $this->assertEquals(11, Sale::where('source', 'pedidosya')->count());
    }

    public function test_sales_can_be_created_after_importing_only_the_amounts(): void
    {
        $id = $this->import(['sales_mode' => 'skip'])->assertCreated()->json('settlement.id');

        $this->actingAs($this->owner)->postJson("/api/finance/pedidosya-settlements/{$id}/sales")
            ->assertStatus(422)->assertJsonValidationErrors('mappings');

        $this->mapNames();
        $preview = $this->actingAs($this->owner)->getJson("/api/finance/pedidosya-settlements/{$id}/sales")->assertOk();
        $this->assertEquals(11, $preview->json('summary.create'));

        $this->actingAs($this->owner)->postJson("/api/finance/pedidosya-settlements/{$id}/sales")
            ->assertOk()->assertJsonPath('sales.created', 11);
        $this->assertEquals(11, Sale::where('source', 'pedidosya')->count());
    }

    public function test_an_order_that_breaks_the_option_rules_is_reported_and_skipped(): void
    {
        OptionGroup::where('name', 'Frutas')->update(['max_selections' => 2]);
        $this->mapNames();

        $preview = $this->preview();
        $row = collect($preview->json('sales.orders'))->firstWhere('order_number', '2287639518');
        $this->assertEquals('error', $row['action']);
        $this->assertStringContainsString('como máximo 2', $row['errors'][0]);

        $res = $this->import()->assertCreated();
        // 2287639518 y 2290720503 (3 frutas) no se crean; el resto sí
        $this->assertEquals(9, $res->json('sales.created'));
        $this->assertCount(1, $res->json('warnings'));
        $this->assertNull(PedidosYaOrder::where('order_number', '2287639518')->value('sale_id'));
    }

    public function test_weekly_report_has_sales_costs_refunds_and_result(): void
    {
        $this->mapNames();
        $id = $this->import()->json('settlement.id');

        $r = $this->actingAs($this->owner)->getJson("/api/finance/pedidosya-settlements/{$id}/report")->assertOk()->json();

        $this->assertEquals(11, $r['sales']['delivered_count']);
        $this->assertEquals(748.0, $r['sales']['gross_sales']);
        $this->assertEquals(68.0, $r['sales']['average_ticket']);
        $this->assertEquals('Classic Bowl', $r['sales']['top_items'][0]['name']);
        $this->assertEquals(25.0, $r['costs']['commission_percent']);
        $this->assertEquals(2, $r['refunds']['cancelled_count']);
        $this->assertEquals(240.0, $r['refunds']['cancelled_amount']);
        $this->assertEquals(224.44, $r['result']['retained_amount']);
        $this->assertEquals(30.0, $r['result']['retained_percent']);
        $this->assertEquals(11, $r['control']['sales_created']);
        $this->assertEquals(0, $r['control']['without_sale']);
    }

    public function test_imported_sales_do_not_change_the_monthly_revenue(): void
    {
        $this->mapNames();
        $before = $this->actingAs($this->owner)->getJson('/api/finance/pnl?year=2026&month=9')->json('revenue.total');
        $this->import()->assertCreated();
        $after = $this->actingAs($this->owner)->getJson('/api/finance/pnl?year=2026&month=9')->json('revenue');

        // La semana importada aporta sus ventas netas (710) una sola vez: las ventas creadas caen en días cubiertos
        $this->assertEquals(0.0, (float) $before);
        $this->assertEquals(710.0, $after['total']);
        $this->assertEquals(0.0, $after['pedidosya_pos_unreconciled']);
    }

    public function test_mappings_can_be_listed_and_deleted(): void
    {
        $this->mapNames();
        $list = $this->actingAs($this->owner)->getJson('/api/finance/pedidosya-mappings')->assertOk()->json();
        $this->assertCount(5, $list);
        $ignored = collect($list)->firstWhere('name', 'syrup de chocolate');
        $this->assertEquals('Sin equivalencia (solo nota)', $ignored['target']);

        $this->actingAs($this->owner)->deleteJson("/api/finance/pedidosya-mappings/{$ignored['id']}")->assertOk();
        $this->assertCount(4, $this->actingAs($this->owner)->getJson('/api/finance/pedidosya-mappings')->json());

        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->getJson('/api/finance/pedidosya-mappings')->assertForbidden();
    }
}
