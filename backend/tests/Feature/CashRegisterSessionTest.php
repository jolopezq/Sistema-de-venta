<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashRegisterSessionTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create([
            'role' => 'cashier',
        ]);
    }

    public function test_cashier_can_open_cash_register_session(): void
    {
        $response = $this->actingAs($this->cashier)
            ->postJson('/api/cash-sessions', [
                'opening_amount' => 200.00,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('session.opening_amount', '200.00')
            ->assertJsonPath('session.status', 'open');

        $this->assertDatabaseHas('cash_register_sessions', [
            'cashier_id' => $this->cashier->id,
            'status' => 'open',
        ]);
    }

    public function test_cashier_cannot_open_duplicate_active_session(): void
    {
        CashRegisterSession::create([
            'cashier_id' => $this->cashier->id,
            'opening_amount' => 150.00,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $response = $this->actingAs($this->cashier)
            ->postJson('/api/cash-sessions', [
                'opening_amount' => 200.00,
            ]);

        $response->assertStatus(409);
    }

    public function test_cashier_can_get_active_session_with_realtime_stats(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id' => $this->cashier->id,
            'opening_amount' => 200.00,
            'status' => 'open',
            'opened_at' => now()->subHours(2),
        ]);

        // Simular una venta en efectivo de 50 Bs
        $sale = Sale::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'cashier_id' => $this->cashier->id,
            'subtotal' => 50.00,
            'total_amount' => 50.00,
            'status' => 'completed',
            'created_at' => now()->subHour(),
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount' => 50.00,
        ]);

        $response = $this->actingAs($this->cashier)
            ->getJson('/api/cash-sessions/active');

        $response->assertStatus(200)
            ->assertJsonPath('session.id', $session->id)
            ->assertJsonPath('stats.cash_sales', 50)
            ->assertJsonPath('stats.expected_cash', 250);
    }

    public function test_cashier_can_close_session_with_arqueo_and_breakdown(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id' => $this->cashier->id,
            'opening_amount' => 200.00,
            'status' => 'open',
            'opened_at' => now()->subHours(4),
        ]);

        $response = $this->actingAs($this->cashier)
            ->postJson("/api/cash-sessions/{$session->id}/close", [
                'actual_closing' => 210.00,
                'bill_breakdown' => [
                    '100' => 2,
                    '10' => 1,
                ],
                'diff_note' => 'Sobrante de propinas',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('session.status', 'closed')
            ->assertJsonPath('session.actual_closing', '210.00')
            ->assertJsonPath('session.difference', '10.00');

        $this->assertDatabaseHas('cash_register_sessions', [
            'id' => $session->id,
            'status' => 'closed',
            'actual_closing' => 210.00,
            'difference' => 10.00,
        ]);
    }

    public function test_sales_index_can_filter_by_date_and_payment_method(): void
    {
        $sale = Sale::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'cashier_id' => $this->cashier->id,
            'subtotal' => 80.00,
            'total_amount' => 80.00,
            'status' => 'completed',
            'created_at' => now(),
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'method' => 'qr',
            'amount' => 80.00,
        ]);

        $response = $this->actingAs($this->cashier)
            ->getJson('/api/sales?date=' . now()->format('Y-m-d') . '&payment_method=qr');

        $response->assertStatus(200)
            ->assertJsonPath('summary.qr_total', 80)
            ->assertJsonPath('summary.sales_count', 1);
    }
}
