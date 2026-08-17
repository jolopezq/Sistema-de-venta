<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
    }

    public function test_can_list_active_orders_in_queue(): void
    {
        Sale::create([
            'id' => (string) Str::uuid(),
            'cashier_id' => $this->cashier->id,
            'subtotal' => 45.00,
            'total_amount' => 45.00,
            'status' => 'completed',
            'preparation_status' => 'received',
            'created_at' => now(),
        ]);

        Sale::create([
            'id' => (string) Str::uuid(),
            'cashier_id' => $this->cashier->id,
            'subtotal' => 60.00,
            'total_amount' => 60.00,
            'status' => 'completed',
            'preparation_status' => 'preparing',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->cashier)->getJson('/api/order-queue');

        $response->assertStatus(200)
            ->assertJsonPath('counts.received', 1)
            ->assertJsonPath('counts.preparing', 1)
            ->assertJsonPath('counts.ready', 0)
            ->assertJsonPath('counts.delivered', 0);
    }

    public function test_can_advance_order_status_in_queue(): void
    {
        $sale = Sale::create([
            'id' => (string) Str::uuid(),
            'cashier_id' => $this->cashier->id,
            'subtotal' => 50.00,
            'total_amount' => 50.00,
            'status' => 'completed',
            'preparation_status' => 'received',
            'created_at' => now(),
        ]);

        // 1. Avanzar a preparing
        $res1 = $this->actingAs($this->cashier)
            ->patchJson("/api/order-queue/{$sale->id}/status", [
                'status' => 'preparing',
            ]);

        $res1->assertStatus(200)
            ->assertJsonPath('order.preparation_status', 'preparing');
        $this->assertNotNull($sale->fresh()->preparation_started_at);

        // 2. Avanzar a ready
        $res2 = $this->actingAs($this->cashier)
            ->patchJson("/api/order-queue/{$sale->id}/status", [
                'status' => 'ready',
            ]);

        $res2->assertStatus(200)
            ->assertJsonPath('order.preparation_status', 'ready');
        $this->assertNotNull($sale->fresh()->ready_at);

        // 3. Avanzar a delivered
        $res3 = $this->actingAs($this->cashier)
            ->patchJson("/api/order-queue/{$sale->id}/status", [
                'status' => 'delivered',
            ]);

        $res3->assertStatus(200)
            ->assertJsonPath('order.preparation_status', 'delivered');
        $this->assertNotNull($sale->fresh()->delivered_at);
    }
}
