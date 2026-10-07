<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PedidosYaPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $product;
    private Option $topping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $this->product = Product::create([
            'name' => 'Bowl', 'price' => 28, 'category_id' => $category->id, 'printer_target' => 'kitchen',
        ]);
        $group = OptionGroup::create(['name' => 'Toppings', 'min_selections' => 0, 'max_selections' => 3]);
        $this->topping = Option::create(['option_group_id' => $group->id, 'name' => 'Coco', 'additional_price' => 4]);
    }

    public function test_admin_saves_pedidosya_prices_and_availability_in_batch(): void
    {
        $this->actingAs($this->admin)->putJson('/api/admin/pricing/pedidosya', [
            'products' => [['id' => $this->product->id, 'delivery_price' => 35, 'available_on_pedidosya' => false]],
            'options'  => [['id' => $this->topping->id, 'delivery_price' => 5]],
        ])->assertOk()
            ->assertJsonPath('updated_products', 1)
            ->assertJsonPath('updated_options', 1);

        $product = $this->product->fresh();
        $this->assertEquals(35, $product->delivery_price);
        $this->assertFalse($product->available_on_pedidosya);
        $this->assertEquals(28, $product->price, 'El precio de mostrador no cambia');

        $topping = $this->topping->fresh();
        $this->assertEquals(5, $topping->delivery_price);
        $this->assertEquals(4, $topping->additional_price, 'El precio de mostrador no cambia');

        $this->assertDatabaseHas('audit_logs', ['module' => 'Product', 'action' => 'updated', 'user_id' => $this->admin->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'Option', 'action' => 'updated', 'user_id' => $this->admin->id]);
    }

    public function test_empty_price_clears_the_pedidosya_price(): void
    {
        $this->product->update(['delivery_price' => 35]);

        $this->actingAs($this->admin)->putJson('/api/admin/pricing/pedidosya', [
            'products' => [['id' => $this->product->id, 'delivery_price' => null]],
        ])->assertOk()->assertJsonPath('updated_products', 1);

        $this->assertNull($this->product->fresh()->delivery_price);
    }

    public function test_unchanged_values_are_not_saved_again(): void
    {
        $this->product->update(['delivery_price' => 35]);

        $this->actingAs($this->admin)->putJson('/api/admin/pricing/pedidosya', [
            'products' => [['id' => $this->product->id, 'delivery_price' => '35.00', 'available_on_pedidosya' => true]],
        ])->assertOk()->assertJsonPath('updated_products', 0);
    }

    public function test_negative_prices_are_rejected(): void
    {
        $this->actingAs($this->admin)->putJson('/api/admin/pricing/pedidosya', [
            'options' => [['id' => $this->topping->id, 'delivery_price' => -1]],
        ])->assertUnprocessable();
    }

    public function test_cashier_cannot_change_the_tariff(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)->putJson('/api/admin/pricing/pedidosya', [
            'products' => [['id' => $this->product->id, 'delivery_price' => 1]],
        ])->assertForbidden();

        $this->assertNull($this->product->fresh()->delivery_price);
    }
}
