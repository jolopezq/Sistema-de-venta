<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PedidosYaAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private User $admin;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
    }

    public function test_products_are_available_on_pedidosya_by_default(): void
    {
        $product = $this->product();

        $this->assertTrue($product->fresh()->available_on_pedidosya);
        $this->actingAs($this->cashier)->getJson('/api/catalog')
            ->assertOk()
            ->assertJsonPath('products.0.available_on_pedidosya', true);
    }

    public function test_admin_can_mark_a_product_as_not_available_on_pedidosya(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin)->putJson("/api/products/{$product->id}", [
            'name' => 'Bowl', 'price' => 28, 'category_id' => $this->category->id,
            'printer_target' => 'kitchen', 'available_on_pedidosya' => false,
        ])->assertOk()->assertJsonPath('data.available_on_pedidosya', false);

        $this->assertFalse($product->fresh()->available_on_pedidosya);
    }

    public function test_updating_a_product_without_the_field_keeps_its_availability(): void
    {
        $product = $this->product(['available_on_pedidosya' => false]);

        $this->actingAs($this->admin)->putJson("/api/products/{$product->id}", [
            'name' => 'Bowl renombrado', 'price' => 28, 'category_id' => $this->category->id,
            'printer_target' => 'kitchen', 'available_on_pedidosya' => null,
        ])->assertOk();

        $this->assertFalse($product->fresh()->available_on_pedidosya);
    }

    public function test_pedidosya_sale_of_unavailable_product_is_accepted_and_audited(): void
    {
        $product = $this->product(['available_on_pedidosya' => false]);
        $sale = [
            'id' => (string) Str::uuid(),
            'order_number' => '071026-' . random_int(1000, 9999),
            'source' => 'pedidosya',
            'subtotal' => 28,
            'total_amount' => 28,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 28, 'subtotal' => 28]],
            'payments' => [['method' => 'delivery_platform', 'amount' => 28]],
        ];

        $this->actingAs($this->cashier)->postJson('/api/sales/sync', ['sales' => [$sale]])
            ->assertOk()
            ->assertJsonPath('data.synced.0', $sale['id']);

        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'source' => 'pedidosya']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sale_sync_discrepancy',
            'description' => "Venta Ticket #{$sale['order_number']} sincronizada con diferencias: Bowl: no está disponible en PedidosYa",
        ]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Bowl', 'price' => 28,
            'category_id' => $this->category->id, 'printer_target' => 'kitchen',
        ], $attributes));
    }
}
