<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KdsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $kitchenUser;
    protected User $cashierUser;
    protected Product $product;
    protected OptionGroup $optionGroup;
    protected Option $option;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchenUser = User::factory()->create([
            'name' => 'Cocinero Test',
            'email' => 'cocinero@test.com',
            'role' => 'kitchen',
        ]);

        $this->cashierUser = User::factory()->create([
            'name' => 'Cajero Test',
            'email' => 'cajero@test.com',
            'role' => 'cashier',
        ]);

        $category = Category::create(['name' => 'Açaí Bowls', 'sort_order' => 1]);

        $this->product = Product::create([
            'name' => 'Classic Bowl',
            'price' => 28.00,
            'category_id' => $category->id,
            'printer_target' => 'kitchen',
        ]);

        $this->optionGroup = OptionGroup::create([
            'name' => 'Toppings Extras',
            'min_selectable' => 0,
            'max_selectable' => 3,
        ]);

        $this->option = Option::create([
            'option_group_id' => $this->optionGroup->id,
            'name' => 'Extra Frutilla',
            'additional_price' => 3.00,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/kds/orders');
        $response->assertStatus(401);
    }

    public function test_kitchen_user_can_access_kds_orders_list(): void
    {
        $sale = Sale::create([
            'id' => (string) Str::uuid(),
            'cashier_id' => $this->cashierUser->id,
            'subtotal' => 31.00,
            'total_amount' => 31.00,
            'status' => 'completed',
            'preparation_status' => 'received',
            'source' => 'pos',
        ]);

        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 28.00,
            'subtotal' => 31.00,
            'item_note' => 'Sin chispas de chocolate',
        ]);

        SaleItemOption::create([
            'sale_item_id' => $item->id,
            'option_id' => $this->option->id,
            'option_group_id' => $this->optionGroup->id,
            'option_name_snapshot' => 'Extra Frutilla',
            'additional_price_snapshot' => 3.00,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->kitchenUser)->getJson('/api/kds/orders');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('counts.received', 1)
            ->assertJsonPath('counts.preparing', 0)
            ->assertJsonPath('orders.0.display_code', '#POS-' . strtoupper(substr($sale->id, 0, 6)))
            ->assertJsonPath('orders.0.items.0.product_name', 'Classic Bowl')
            ->assertJsonPath('orders.0.items.0.item_note', 'Sin chispas de chocolate')
            ->assertJsonPath('orders.0.items.0.options.0.option_name', 'Extra Frutilla');
    }

    public function test_kds_order_status_progression_with_timestamps(): void
    {
        $sale = Sale::create([
            'id' => (string) Str::uuid(),
            'cashier_id' => $this->cashierUser->id,
            'subtotal' => 28.00,
            'total_amount' => 28.00,
            'status' => 'completed',
            'preparation_status' => 'received',
            'source' => 'pos',
        ]);

        // 1. Pasar a 'preparing'
        $responsePrep = $this->actingAs($this->kitchenUser)->patchJson("/api/kds/orders/{$sale->id}/status", [
            'status' => 'preparing',
        ]);

        $responsePrep->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.preparation_status', 'preparing');

        $sale->refresh();
        $this->assertEquals('preparing', $sale->preparation_status);
        $this->assertNotNull($sale->preparation_started_at);

        // 2. Pasar a 'ready'
        $responseReady = $this->actingAs($this->kitchenUser)->patchJson("/api/kds/orders/{$sale->id}/status", [
            'status' => 'ready',
        ]);

        $responseReady->assertStatus(200)
            ->assertJsonPath('order.preparation_status', 'ready');

        $sale->refresh();
        $this->assertEquals('ready', $sale->preparation_status);
        $this->assertNotNull($sale->ready_at);

        // 3. Pasar a 'delivered'
        $responseDeliv = $this->actingAs($this->kitchenUser)->patchJson("/api/kds/orders/{$sale->id}/status", [
            'status' => 'delivered',
        ]);

        $responseDeliv->assertStatus(200)
            ->assertJsonPath('order.preparation_status', 'delivered');

        $sale->refresh();
        $this->assertEquals('delivered', $sale->preparation_status);
        $this->assertNotNull($sale->delivered_at);
    }

    public function test_kds_stats_endpoint_returns_metrics(): void
    {
        Sale::create([
            'id' => (string) Str::uuid(),
            'cashier_id' => $this->cashierUser->id,
            'subtotal' => 28.00,
            'total_amount' => 28.00,
            'status' => 'completed',
            'preparation_status' => 'received',
            'source' => 'pos',
        ]);

        Sale::create([
            'id' => (string) Str::uuid(),
            'cashier_id' => $this->cashierUser->id,
            'subtotal' => 28.00,
            'total_amount' => 28.00,
            'status' => 'completed',
            'preparation_status' => 'ready',
            'source' => 'pedidosya',
        ]);

        $response = $this->actingAs($this->kitchenUser)->getJson('/api/kds/stats');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('stats.active_orders', 1)
            ->assertJsonPath('stats.ready_orders', 1)
            ->assertJsonPath('stats.channel_breakdown.pos', 1)
            ->assertJsonPath('stats.channel_breakdown.pedidosya', 1);
    }
}
