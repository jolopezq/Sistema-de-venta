<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PedidosYaPriceOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', config('app.timezone')));

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $this->product = Product::create([
            'name' => 'Bowl', 'price' => 28, 'delivery_price' => 35,
            'category_id' => $category->id, 'printer_target' => 'kitchen',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pedidosya_line_with_adjusted_price_and_reason_is_stored_and_audited(): void
    {
        $sale = $this->sale('pedidosya', 30, 'Promo PedidosYa');

        $this->sync($sale);

        $this->assertSame('Promo PedidosYa', SaleItem::where('sale_id', $sale['id'])->value('price_override_reason'));
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'total_amount' => 30]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pedidosya_price_override',
            'user_id' => $this->cashier->id,
            'description' => "Pedido PedidosYa Ticket #{$sale['order_number']} con precio ajustado: Bowl: tarifario 35, cobrado 30. Motivo: Promo PedidosYa",
        ]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_sync_discrepancy']);
    }

    public function test_pedidosya_line_with_different_price_and_no_reason_is_a_discrepancy(): void
    {
        $sale = $this->sale('pedidosya', 30);

        $this->sync($sale);

        $this->assertNull(SaleItem::where('sale_id', $sale['id'])->value('price_override_reason'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale_sync_discrepancy']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'pedidosya_price_override']);
    }

    public function test_reason_is_dropped_when_price_matches_the_tariff(): void
    {
        $sale = $this->sale('pedidosya', 35, 'Promo PedidosYa');

        $this->sync($sale);

        $this->assertNull(SaleItem::where('sale_id', $sale['id'])->value('price_override_reason'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'pedidosya_price_override']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_sync_discrepancy']);
    }

    public function test_reason_on_a_local_sale_is_ignored_and_audited(): void
    {
        $sale = $this->sale('pos', 20, 'Precio desactualizado', 'cash');

        $this->sync($sale);

        $this->assertNull(SaleItem::where('sale_id', $sale['id'])->value('price_override_reason'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'pedidosya_price_override']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sale_sync_discrepancy',
            'description' => "Venta Ticket #{$sale['order_number']}: Bowl: ajuste de precio a 20 con motivo \"Precio desactualizado\" ignorado (solo se permite en pedidos PedidosYa)",
        ]);
        // Además, el precio distinto al de mostrador sigue contando como discrepancia
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sale_sync_discrepancy',
            'description' => "Venta Ticket #{$sale['order_number']} sincronizada con diferencias: Bowl: cobrado 20, catálogo 28",
        ]);
    }

    public function test_admin_pedidosya_sale_keeps_the_override_reason(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/sales', [
            'created_at' => now()->subHour()->toDateTimeString(),
            'source' => 'pedidosya',
            'subtotal' => 32,
            'total_amount' => 32,
            'edit_reason' => 'Pedido PedidosYa ingresado a mano',
            'items' => [[
                'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 32, 'subtotal' => 32,
                'price_override_reason' => 'Precio desactualizado',
            ]],
            'payments' => [['method' => 'delivery_platform', 'amount' => 32]],
        ])->assertCreated();

        $saleId = $response->json('sale.id');
        $this->assertSame('Precio desactualizado', SaleItem::where('sale_id', $saleId)->value('price_override_reason'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'pedidosya_price_override', 'user_id' => $this->admin->id]);
    }

    private function sale(string $source, float $unitPrice, ?string $reason = null, string $method = 'delivery_platform'): array
    {
        return [
            'id' => (string) Str::uuid(),
            'order_number' => '071026-' . random_int(1000, 9999),
            'source' => $source,
            'subtotal' => $unitPrice,
            'total_amount' => $unitPrice,
            'items' => [[
                'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => $unitPrice, 'subtotal' => $unitPrice,
                'price_override_reason' => $reason,
            ]],
            'payments' => [['method' => $method, 'amount' => $unitPrice]],
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
