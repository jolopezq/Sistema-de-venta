<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cierre de caja confiable (análisis del POS, prioridad 1): el POS sabe si una venta tendría caja,
 * el cierre no se hace con cifras viejas y no se cierra con ventas sin sincronizar (salvo un admin con motivo).
 */
class CashCloseSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 15:00:00', config('app.timezone')));

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // --- La caja que recibiría una venta ---

    public function test_active_reports_own_open_session_as_sales_session(): void
    {
        $session = $this->openSession($this->cashier);

        $this->actingAs($this->cashier)->getJson('/api/cash-sessions/active')
            ->assertOk()
            ->assertJsonPath('session.id', $session->id)
            ->assertJsonPath('sales_session_id', $session->id);
    }

    public function test_cashier_without_own_session_sells_into_shared_admin_session(): void
    {
        $adminSession = $this->openSession($this->admin);

        $this->actingAs($this->cashier)->getJson('/api/cash-sessions/active')
            ->assertOk()
            ->assertJsonPath('session', null)
            ->assertJsonPath('sales_session_id', $adminSession->id);
    }

    public function test_admin_without_own_session_sells_into_the_open_cashier_session(): void
    {
        // Una sola gaveta: lo que cobra el dueño durante el turno de un cajero entra a ese turno
        $cashierSession = $this->openSession($this->cashier);

        $this->actingAs($this->admin)->getJson('/api/cash-sessions/active')
            ->assertOk()
            ->assertJsonPath('session.id', $cashierSession->id)
            ->assertJsonPath('sales_session_id', $cashierSession->id);
    }

    public function test_cashier_does_not_sell_into_another_cashiers_session(): void
    {
        $other = User::factory()->create(['role' => 'cashier']);
        $this->openSession($other);

        $this->actingAs($this->cashier)->getJson('/api/cash-sessions/active')
            ->assertOk()
            ->assertJsonPath('sales_session_id', null);
    }

    public function test_no_sales_session_when_no_cash_register_is_open(): void
    {
        // Una caja olvidada de ayer no recibe ventas de hoy
        $this->openSession($this->cashier, now()->subDay());

        $this->actingAs($this->cashier)->getJson('/api/cash-sessions/active')
            ->assertOk()
            ->assertJsonPath('session', null)
            ->assertJsonPath('sales_session_id', null);
    }

    // --- El esperado que vio el cajero ---

    public function test_close_is_rejected_when_expected_cash_changed_while_counting(): void
    {
        $session = $this->openSession($this->cashier);
        $this->cashSale(50, now()->subHour());

        // El cajero vio 200 (antes de la venta de 50); el servidor calcula 250
        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$session->id}/close", $this->closePayload(200, 200))
            ->assertStatus(409)
            ->assertJsonPath('stats.expected_cash', 250);

        $this->assertSame('open', $session->fresh()->status);
    }

    public function test_close_succeeds_when_expected_cash_matches(): void
    {
        $session = $this->openSession($this->cashier);
        $this->cashSale(50, now()->subHour());

        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$session->id}/close", $this->closePayload(250, 250))
            ->assertOk()
            ->assertJsonPath('session.status', 'closed')
            ->assertJsonPath('session.difference', '0.00');
    }

    public function test_close_without_expected_seen_keeps_working(): void
    {
        // Compatibilidad: un POS con la versión anterior no envía el esperado
        $session = $this->openSession($this->cashier);

        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$session->id}/close", [
            'actual_closing' => 200, 'cash_withdrawn' => 0, 'cash_left_for_next' => 200,
        ])->assertOk();
    }

    // --- Ventas sin sincronizar ---

    public function test_cashier_cannot_close_with_unsynced_sales(): void
    {
        $session = $this->openSession($this->cashier);

        $this->actingAs($this->cashier)
            ->postJson("/api/cash-sessions/{$session->id}/close", $this->closePayload(200, 200) + ['unsynced_sales' => 2])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '2 ventas'));

        $this->assertSame('open', $session->fresh()->status);
    }

    public function test_admin_must_give_a_reason_to_force_close_with_unsynced_sales(): void
    {
        $session = $this->openSession($this->cashier);

        $this->actingAs($this->admin)
            ->postJson("/api/cash-sessions/{$session->id}/close", $this->closePayload(200, 200) + ['unsynced_sales' => 1])
            ->assertStatus(422);

        $this->assertSame('open', $session->fresh()->status);
    }

    public function test_admin_forced_close_keeps_reason_in_note_and_audit(): void
    {
        $session = $this->openSession($this->cashier);

        $this->actingAs($this->admin)
            ->postJson("/api/cash-sessions/{$session->id}/close", $this->closePayload(200, 200) + [
                'unsynced_sales' => 1,
                'force_reason'   => 'La tablet se quedó sin red; la venta se revisa mañana',
            ])
            ->assertOk()
            ->assertJsonPath('session.status', 'closed');

        $this->assertStringContainsString('Cierre forzado con 1 venta sin sincronizar', $session->fresh()->diff_note);
        $this->assertTrue(AuditLog::where('action', 'cash_close_forced')->where('user_id', $this->admin->id)->exists());
    }

    // --- Motivo legible de una venta rechazada ---

    public function test_sync_rejection_reason_is_readable_for_the_cashier(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/sales/sync', ['sales' => [[
            'id'           => (string) Str::uuid(),
            'subtotal'     => 20,
            'total_amount' => 20,
            'items'        => [['product_id' => 999999, 'quantity' => 1, 'unit_price' => 20, 'subtotal' => 20]],
            'payments'     => [['method' => 'cash', 'amount' => 20]],
        ]]])
            ->assertStatus(422)
            ->assertJsonPath('errors', fn ($errors) => in_array(
                'Un producto de la venta ya no existe en el catálogo.',
                collect($errors)->flatten()->all(),
                true
            ));
    }

    // --- Ayudas ---

    private function openSession(User $user, ?Carbon $at = null): CashRegisterSession
    {
        return CashRegisterSession::create([
            'cashier_id'     => $user->id,
            'opening_amount' => 200,
            'status'         => 'open',
            'opened_at'      => $at ?? now()->subHours(3),
        ]);
    }

    private function cashSale(float $amount, Carbon $at): Sale
    {
        $sale = Sale::create([
            'id'           => (string) Str::uuid(),
            'cashier_id'   => $this->cashier->id,
            'subtotal'     => $amount,
            'total_amount' => $amount,
            'status'       => 'completed',
            'created_at'   => $at,
        ]);
        SalePayment::create(['sale_id' => $sale->id, 'method' => 'cash', 'amount' => $amount]);

        return $sale;
    }

    private function closePayload(float $counted, float $expectedSeen): array
    {
        return [
            'actual_closing'     => $counted,
            'cash_withdrawn'     => 0,
            'cash_left_for_next' => $counted,
            'expected_seen'      => $expectedSeen,
        ];
    }
}
