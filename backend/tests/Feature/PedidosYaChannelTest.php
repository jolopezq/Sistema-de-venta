<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PedidosYaChannelTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private User $admin;
    private Product $product;
    private Option $topping;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 15:00:00', config('app.timezone')));

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $this->product = Product::create([
            'name' => 'Bowl', 'price' => 30, 'delivery_price' => 36,
            'category_id' => $category->id, 'printer_target' => 'kitchen',
        ]);
        $group = OptionGroup::create(['name' => 'Toppings', 'min_selections' => 0, 'max_selections' => 3]);
        $this->topping = Option::create([
            'option_group_id' => $group->id, 'name' => 'Frutilla', 'additional_price' => 3, 'delivery_price' => 4,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pedidosya_sales_are_excluded_from_cash_count_by_source(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id' => $this->cashier->id, 'opening_amount' => 100, 'status' => 'open', 'opened_at' => now()->subHour(),
        ]);

        $this->createSale('pos', 50, 'cash');
        $this->createSale('pedidosya', 38, 'delivery_platform');
        // Mal registrado como efectivo: igual no debe sumar a la gaveta
        $this->createSale('pedidosya', 20, 'cash');

        $stats = $this->actingAs($this->cashier)->getJson("/api/cash-sessions/{$session->id}")
            ->assertOk()
            ->json('stats');

        $this->assertEquals(150, $stats['expected_cash']);
        $this->assertEquals(50, $stats['total_sales']);
        $this->assertEquals(50, $stats['cash_sales']);
        $this->assertEquals(1, $stats['sales_count']);
        $this->assertEquals(2, $stats['pedidosya_count']);
        $this->assertEquals(58, $stats['pedidosya_total']);
    }

    public function test_pos_pedidosya_sale_at_delivery_price_has_no_discrepancy(): void
    {
        // 36 del producto + 4 del topping a tarifa PedidosYa
        $sale = $this->posSale(unitPrice: 40, payments: [['method' => 'delivery_platform', 'amount' => 40]]);

        $this->sync($sale);

        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'source' => 'pedidosya', 'total_amount' => 40]);
        $this->assertDatabaseHas('sale_item_options', ['option_id' => $this->topping->id, 'additional_price_snapshot' => 4]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_sync_discrepancy']);
    }

    public function test_pedidosya_without_delivery_price_falls_back_to_local_price(): void
    {
        $this->product->update(['delivery_price' => null]);
        $this->topping->update(['delivery_price' => null]);

        $sale = $this->posSale(unitPrice: 33, payments: [['method' => 'delivery_platform', 'amount' => 33]]);

        $this->sync($sale);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_sync_discrepancy']);
    }

    public function test_local_price_on_pos_channel_is_still_checked_against_counter_price(): void
    {
        $sale = $this->posSale(unitPrice: 40, payments: [['method' => 'cash', 'amount' => 40]], source: 'pos');

        $this->sync($sale);

        $this->assertDatabaseHas('audit_logs', ['action' => 'sale_sync_discrepancy']);
    }

    public function test_pedidosya_payments_sent_as_cash_become_platform_payment(): void
    {
        $sale = $this->posSale(unitPrice: 40, payments: [['method' => 'cash', 'amount' => 50]]);

        $this->sync($sale);

        $payments = SalePayment::where('sale_id', $sale['id'])->get();
        $this->assertCount(1, $payments);
        $this->assertSame('delivery_platform', $payments[0]->method);
        $this->assertEquals(40, $payments[0]->amount);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale_sync_discrepancy']);
    }

    public function test_admin_pedidosya_sale_with_cash_payment_is_stored_as_platform_payment(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/sales', [
            'created_at' => now()->subHour()->toDateTimeString(),
            'source' => 'pedidosya',
            'subtotal' => 36,
            'total_amount' => 36,
            'edit_reason' => 'Pedido PedidosYa ingresado a mano',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 36, 'subtotal' => 36]],
            'payments' => [['method' => 'cash', 'amount' => 36]],
        ])->assertCreated();

        $this->assertDatabaseHas('sale_payments', ['sale_id' => $response->json('sale.id'), 'method' => 'delivery_platform', 'amount' => 36]);
        $this->assertDatabaseMissing('sale_payments', ['sale_id' => $response->json('sale.id'), 'method' => 'cash']);
    }

    private function createSale(string $source, float $total, string $method): void
    {
        $sale = Sale::create([
            'id' => (string) Str::uuid(), 'order_number' => '041026-' . random_int(1000, 9999),
            'cashier_id' => $this->cashier->id, 'subtotal' => $total, 'total_amount' => $total,
            'status' => 'completed', 'source' => $source, 'created_at' => now(),
        ]);
        SalePayment::create(['sale_id' => $sale->id, 'method' => $method, 'amount' => $total]);
    }

    private function posSale(float $unitPrice, array $payments, string $source = 'pedidosya'): array
    {
        return [
            'id' => (string) Str::uuid(),
            'order_number' => '041026-' . random_int(1000, 9999),
            'source' => $source,
            'subtotal' => $unitPrice,
            'total_amount' => $unitPrice,
            'items' => [[
                'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => $unitPrice, 'subtotal' => $unitPrice,
                'modifiers' => [['option_id' => $this->topping->id, 'option_name' => 'Frutilla', 'quantity' => 1]],
            ]],
            'payments' => $payments,
        ];
    }

    private function sync(array $sale): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/sales/sync', ['sales' => [$sale]])
            ->assertOk()
            ->assertJsonPath('data.synced.0', $sale['id']);
    }
}
