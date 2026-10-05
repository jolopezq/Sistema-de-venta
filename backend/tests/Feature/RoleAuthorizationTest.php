<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_read_but_not_modify_catalog(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);

        $this->actingAs($cashier)->getJson('/api/categories')->assertOk();
        $this->actingAs($cashier)->postJson('/api/categories', ['name' => 'Nueva'])->assertForbidden();
        $this->actingAs($cashier)->deleteJson("/api/categories/{$category->id}")->assertForbidden();
        $this->actingAs($cashier)->putJson('/api/loyalty-config', [])->assertForbidden();
        $this->actingAs($cashier)->postJson('/api/inventory/movements', [])->assertForbidden();
    }

    public function test_cashier_cannot_void_sales(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $sale = $this->createSale($cashier);

        $this->actingAs($cashier)
            ->postJson("/api/sales/{$sale->id}/void", ['void_reason' => 'Prueba'])
            ->assertForbidden();

        $this->assertSame('completed', $sale->fresh()->status);
    }

    public function test_kitchen_cannot_access_sales_or_cash_sessions_but_can_use_kds(): void
    {
        $kitchen = User::factory()->create(['role' => 'kitchen']);

        $this->actingAs($kitchen)->postJson('/api/sales/sync', ['sales' => []])->assertForbidden();
        $this->actingAs($kitchen)->getJson('/api/sales')->assertForbidden();
        $this->actingAs($kitchen)->getJson('/api/cash-sessions/active')->assertForbidden();
        $this->actingAs($kitchen)->getJson('/api/kds/orders')->assertOk();
        $this->actingAs($kitchen)->getJson('/api/order-queue')->assertOk();
    }

    public function test_cashier_cannot_operate_another_cashiers_session(): void
    {
        $owner = User::factory()->create(['role' => 'cashier']);
        $other = User::factory()->create(['role' => 'cashier']);
        $session = CashRegisterSession::create([
            'cashier_id' => $owner->id,
            'opening_amount' => 100,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($other)->getJson("/api/cash-sessions/{$session->id}")->assertForbidden();
        $this->actingAs($other)->getJson("/api/cash-sessions/{$session->id}/report")->assertForbidden();
        $this->actingAs($other)->postJson("/api/cash-sessions/{$session->id}/movements", [
            'type' => 'expense', 'amount' => 10, 'description' => 'Hielo',
        ])->assertForbidden();
        $this->actingAs($other)->postJson("/api/cash-sessions/{$session->id}/close", [
            'actual_closing' => 100, 'cash_withdrawn' => 100, 'cash_left_for_next' => 0,
        ])->assertForbidden();

        $this->actingAs($other)->getJson('/api/cash-sessions')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($owner)->getJson("/api/cash-sessions/{$session->id}")->assertOk();
    }

    public function test_cashier_cannot_open_backdated_session(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)
            ->postJson('/api/cash-sessions', ['opening_amount' => 100, 'date' => now()->subDays(3)->format('Y-m-d')])
            ->assertForbidden();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'caja@ohana.test']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['email' => 'caja@ohana.test', 'password' => 'incorrecta'])->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'caja@ohana.test', 'password' => 'incorrecta'])->assertStatus(429);
    }

    private function createSale(User $cashier): Sale
    {
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $product = Product::create(['name' => 'Bowl', 'price' => 35, 'category_id' => $category->id, 'printer_target' => 'kitchen']);

        $sale = Sale::create([
            'id' => (string) Str::uuid(),
            'cashier_id' => $cashier->id,
            'subtotal' => 35,
            'total_amount' => 35,
            'status' => 'completed',
            'source' => 'pos',
        ]);
        $sale->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 35, 'subtotal' => 35]);

        return $sale;
    }
}
