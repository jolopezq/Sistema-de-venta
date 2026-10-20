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
        // La caja abierta hace 2 h debe ser «de hoy»: a fija mediodía para que no falle de madrugada
        $this->travelTo(now()->setTime(12, 0));

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
                'cash_withdrawn' => 10.00,
                'cash_left_for_next' => 200.00,
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
            'cash_withdrawn' => 10.00,
            'cash_left_for_next' => 200.00,
        ]);
    }

    public function test_close_rejects_inconsistent_bill_breakdown(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id' => $this->cashier->id,
            'opening_amount' => 200.00,
            'status' => 'open',
            'opened_at' => now()->subHours(4),
        ]);
        $base = ['actual_closing' => 210.00, 'cash_withdrawn' => 10.00, 'cash_left_for_next' => 200.00];

        // La suma del desglose (Bs 300) no coincide con lo contado (Bs 210)
        $this->actingAs($this->cashier)
            ->postJson("/api/cash-sessions/{$session->id}/close", $base + ['bill_breakdown' => ['100' => 3]])
            ->assertStatus(422);

        // Denominación inexistente
        $this->actingAs($this->cashier)
            ->postJson("/api/cash-sessions/{$session->id}/close", $base + ['bill_breakdown' => ['30' => 7]])
            ->assertStatus(422);

        // Cantidad negativa o con decimales
        $this->actingAs($this->cashier)
            ->postJson("/api/cash-sessions/{$session->id}/close", $base + ['bill_breakdown' => ['100' => -2, '10' => 41]])
            ->assertStatus(422);
        $this->actingAs($this->cashier)
            ->postJson("/api/cash-sessions/{$session->id}/close", $base + ['bill_breakdown' => ['100' => 2.5]])
            ->assertStatus(422);

        $this->assertDatabaseHas('cash_register_sessions', ['id' => $session->id, 'status' => 'open']);
    }

    public function test_close_accepts_breakdown_with_fifty_cents(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id' => $this->cashier->id,
            'opening_amount' => 200.00,
            'status' => 'open',
            'opened_at' => now()->subHours(4),
        ]);

        $this->actingAs($this->cashier)
            ->postJson("/api/cash-sessions/{$session->id}/close", [
                'actual_closing' => 851.00,
                'cash_withdrawn' => 651.00,
                'cash_left_for_next' => 200.00,
                'bill_breakdown' => ['200' => 3, '50' => 5, '0.50' => 2],
                'diff_note' => 'Conteo de prueba',
            ])
            ->assertStatus(200)
            ->assertJsonPath('session.status', 'closed');
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

    public function test_cash_expense_reduces_expected_cash_but_qr_expense_does_not(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id' => $this->cashier->id,
            'opening_amount' => 100.00,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Registrar un gasto en efectivo de 30 Bs
        $resCash = $this->actingAs($this->cashier)
            ->postJson("/api/cash-sessions/{$session->id}/movements", [
                'type' => 'expense',
                'payment_method' => 'cash',
                'amount' => 30.00,
                'description' => 'Compra de bolsas en efectivo',
            ]);

        $resCash->assertStatus(201)
            ->assertJsonPath('stats.cash_expenses', 30)
            ->assertJsonPath('stats.expected_cash', 70); // 100 - 30 = 70

        // Registrar un gasto por QR de 20 Bs
        $resQr = $this->actingAs($this->cashier)
            ->postJson("/api/cash-sessions/{$session->id}/movements", [
                'type' => 'expense',
                'payment_method' => 'qr',
                'amount' => 20.00,
                'description' => 'Pago de insumos por transferencia QR',
            ]);

        $resQr->assertStatus(201)
            ->assertJsonPath('stats.total_expenses', 50)
            ->assertJsonPath('stats.cash_expenses', 30)
            ->assertJsonPath('stats.non_cash_expenses', 20)
            ->assertJsonPath('stats.expected_cash', 70); // La gaveta física sigue en 70 Bs

        // Verificar que GET /api/cash-sessions/{id}/movements lista los movimientos
        $resList = $this->actingAs($this->cashier)
            ->getJson("/api/cash-sessions/{$session->id}/movements");

        $resList->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['payment_method' => 'cash', 'amount' => '30.00'])
            ->assertJsonFragment(['payment_method' => 'qr', 'amount' => '20.00']);
    }
}
