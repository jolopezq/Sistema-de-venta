<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionGroupPedidosYaPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private User $admin;
    private Product $bowl;
    private OptionGroup $sizeGroup;
    private OptionGroup $toppingGroup;
    private Option $ohanaSize;
    private Option $topping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->admin = User::factory()->create(['role' => 'admin']);

        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $this->bowl = Product::create([
            'name'           => 'Bowl Clasico',
            'price'          => 30,
            'delivery_price' => 30,
            'category_id'    => $category->id,
            'printer_target' => 'kitchen',
        ]);

        $this->sizeGroup = OptionGroup::create([
            'name'              => 'Tamaño Acai',
            'min_selections'    => 1,
            'max_selections'    => 1,
            'pedidosya_pricing' => true,
        ]);

        $this->ohanaSize = Option::create([
            'option_group_id'  => $this->sizeGroup->id,
            'name'             => 'Ohana',
            'additional_price' => 32,
            'delivery_price'   => 36,
        ]);

        $this->toppingGroup = OptionGroup::create([
            'name'              => 'Toppings',
            'min_selections'    => 0,
            'max_selections'    => 5,
            'pedidosya_pricing' => false,
        ]);

        $this->topping = Option::create([
            'option_group_id'  => $this->toppingGroup->id,
            'name'             => 'Granola',
            'additional_price' => 4,
            'delivery_price'   => 5, // Legacy delivery_price en base que debe ser ignorado
        ]);

        $this->bowl->optionGroups()->attach([$this->sizeGroup->id, $this->toppingGroup->id]);
    }

    /**
     * Caso 1: Pedido PedidosYa con tamaño Ohana (grupo marcado, +36) -> importe con +36, sin discrepancia.
     */
    public function test_pedidosya_order_with_size_ohana_charges_delivery_price_without_discrepancy(): void
    {
        // 30 bowl + 36 Ohana = 66
        $sale = $this->makeSalePayload(66, source: 'pedidosya', options: [
            ['option_id' => $this->ohanaSize->id, 'option_name' => 'Ohana', 'quantity' => 1],
        ]);

        $this->syncSale($sale);

        $this->assertDatabaseHas('sales', [
            'id'           => $sale['id'],
            'source'       => 'pedidosya',
            'total_amount' => 66,
        ]);
        $this->assertDatabaseHas('sale_item_options', [
            'option_id'                  => $this->ohanaSize->id,
            'additional_price_snapshot' => 36,
        ]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_sync_discrepancy']);
    }

    /**
     * Caso 2: Pedido PedidosYa con topping que tiene delivery_price 5 (grupo no marcado) -> se cobra +4;
     * un cobro de +5 queda como discrepancia.
     */
    public function test_pedidosya_order_with_topping_uses_local_price_and_flags_unexpected_delivery_price_charge(): void
    {
        // 30 bowl + 32 Ohana + 4 Granola (precio local) = 66 esperado si cobrado correctamente a precio local
        $saleCorrect = $this->makeSalePayload(30 + 36 + 4, source: 'pedidosya', options: [
            ['option_id' => $this->ohanaSize->id, 'option_name' => 'Ohana', 'quantity' => 1],
            ['option_id' => $this->topping->id, 'option_name' => 'Granola', 'quantity' => 1],
        ]);

        $this->syncSale($saleCorrect);

        $this->assertDatabaseHas('sale_item_options', [
            'option_id'                  => $this->topping->id,
            'additional_price_snapshot' => 4, // Toma el adicional local, ignora delivery_price 5
        ]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_sync_discrepancy']);

        // Ahora, si el POS cobra +5 en vez de +4 para el topping (total 30 + 36 + 5 = 71):
        $saleWithMismatch = $this->makeSalePayload(71, source: 'pedidosya', options: [
            ['option_id' => $this->ohanaSize->id, 'option_name' => 'Ohana', 'quantity' => 1],
            ['option_id' => $this->topping->id, 'option_name' => 'Granola', 'quantity' => 1],
        ]);

        $this->syncSale($saleWithMismatch);

        $this->assertDatabaseHas('audit_logs', [
            'action'  => 'sale_sync_discrepancy',
            'user_id' => $this->cashier->id,
        ]);
    }

    /**
     * Caso 3: Venta local con tamaño -> siempre +32.
     */
    public function test_local_pos_sale_with_size_charges_additional_price_local(): void
    {
        // 30 bowl + 32 Ohana = 62
        $sale = $this->makeSalePayload(62, source: 'pos', options: [
            ['option_id' => $this->ohanaSize->id, 'option_name' => 'Ohana', 'quantity' => 1],
        ], paymentMethod: 'cash');

        $this->syncSale($sale);

        $this->assertDatabaseHas('sales', [
            'id'           => $sale['id'],
            'source'       => 'pos',
            'total_amount' => 62,
        ]);
        $this->assertDatabaseHas('sale_item_options', [
            'option_id'                  => $this->ohanaSize->id,
            'additional_price_snapshot' => 32,
        ]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_sync_discrepancy']);
    }

    /**
     * Caso 4: Tarifario: guardar delivery_price de un topping -> 422; de un tamaño -> se guarda y se audita.
     */
    public function test_tariff_endpoint_validates_group_pricing(): void
    {
        // Topping (no marcado) -> 422
        $this->actingAs($this->admin)->putJson('/api/admin/pricing/pedidosya', [
            'options' => [['id' => $this->topping->id, 'delivery_price' => 6]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['options']);

        // Tamaño (marcado) -> 200 y se audita
        $this->actingAs($this->admin)->putJson('/api/admin/pricing/pedidosya', [
            'options' => [['id' => $this->ohanaSize->id, 'delivery_price' => 38]],
        ])->assertOk()
            ->assertJsonPath('updated_options', 1);

        $this->assertEquals(38, $this->ohanaSize->fresh()->delivery_price);
        $this->assertDatabaseHas('audit_logs', [
            'module'  => 'Option',
            'action'  => 'updated',
            'user_id' => $this->admin->id,
        ]);
    }

    private function makeSalePayload(float $total, string $source, array $options, string $paymentMethod = 'delivery_platform'): array
    {
        return [
            'id'           => (string) Str::uuid(),
            'order_number' => '071026-' . random_int(1000, 9999),
            'source'       => $source,
            'subtotal'     => $total,
            'total_amount' => $total,
            'items'        => [[
                'product_id' => $this->bowl->id,
                'quantity'   => 1,
                'unit_price' => $total,
                'subtotal'   => $total,
                'modifiers'  => $options,
            ]],
            'payments'     => [['method' => $paymentMethod, 'amount' => $total]],
        ];
    }

    private function syncSale(array $sale): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/sales/sync', ['sales' => [$sale]])
            ->assertOk()
            ->assertJsonPath('data.synced.0', $sale['id']);
    }
}
