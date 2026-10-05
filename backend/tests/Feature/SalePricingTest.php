<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalePricingTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $this->product = Product::create([
            'name' => 'Bowl', 'price' => 30, 'category_id' => $category->id, 'printer_target' => 'kitchen',
        ]);
    }

    public function test_server_recalculates_subtotals_and_total(): void
    {
        $sale = $this->sale(['quantity' => 2, 'unit_price' => 30, 'subtotal' => 10], subtotal: 10, total: 10, payments: [['method' => 'qr', 'amount' => 60]]);

        $this->sync($sale);

        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'subtotal' => 60, 'total_amount' => 60]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale['id'], 'subtotal' => 60]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale_sync_discrepancy']);
    }

    public function test_cash_overpayment_is_treated_as_change(): void
    {
        $sale = $this->sale(['quantity' => 1, 'unit_price' => 30, 'subtotal' => 30], subtotal: 30, total: 30, payments: [
            ['method' => 'cash', 'amount' => 50],
            ['method' => 'qr', 'amount' => 10],
        ]);

        $this->sync($sale);

        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale['id'], 'method' => 'cash', 'amount' => 20]);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale['id'], 'method' => 'qr', 'amount' => 10]);
    }

    public function test_price_different_from_catalog_is_accepted_and_audited(): void
    {
        $sale = $this->sale(['quantity' => 1, 'unit_price' => 25, 'subtotal' => 25], subtotal: 25, total: 25, payments: [['method' => 'cash', 'amount' => 25]]);

        $this->sync($sale);

        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'total_amount' => 25]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale_sync_discrepancy', 'description' => "Venta Ticket #{$sale['order_number']} sincronizada con diferencias: Bowl: cobrado 25, catálogo 30"]);
    }

    public function test_consistent_sale_produces_no_discrepancy(): void
    {
        $weightProduct = Product::create([
            'name' => 'Autoservicio', 'price' => 12, 'is_weight_based' => true, 'price_per_gram' => 0.12,
            'category_id' => $this->product->category_id, 'printer_target' => 'kitchen',
        ]);

        $sale = $this->sale(['quantity' => 1, 'unit_price' => 30, 'subtotal' => 30], subtotal: 72, total: 72, payments: [['method' => 'cash', 'amount' => 72]]);
        $sale['items'][] = ['product_id' => $weightProduct->id, 'quantity' => 1, 'unit_price' => 42, 'subtotal' => 42, 'weight_grams' => 350];

        $this->sync($sale);

        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'total_amount' => 72]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_sync_discrepancy']);
    }

    public function test_unknown_payment_method_is_rejected(): void
    {
        $sale = $this->sale(['quantity' => 1, 'unit_price' => 30, 'subtotal' => 30], subtotal: 30, total: 30, payments: [['method' => 'bitcoin', 'amount' => 30]]);

        $this->actingAs($this->cashier)->postJson('/api/sales/sync', ['sales' => [$sale]])->assertStatus(422);
    }

    private function sale(array $item, float $subtotal, float $total, array $payments): array
    {
        return [
            'id' => (string) Str::uuid(),
            'order_number' => '041026-' . random_int(1000, 9999),
            'subtotal' => $subtotal,
            'total_amount' => $total,
            'items' => [array_merge(['product_id' => $this->product->id], $item)],
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
