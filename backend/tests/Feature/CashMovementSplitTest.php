<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\CashSessionClosing;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gasto de caja dividido entre efectivo de la gaveta y QR o banco (docs/PLAN_GASTO_PAGO_DIVIDIDO.md).
 */
class CashMovementSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private CashRegisterSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Ana']);
        $this->session = CashRegisterSession::create([
            'cashier_id' => $this->cashier->id, 'opening_amount' => 200, 'status' => 'open', 'opened_at' => now()->subHours(3),
        ]);
        $sale = Sale::create([
            'id' => (string) Str::uuid(), 'cashier_id' => $this->cashier->id, 'subtotal' => 100, 'total_amount' => 100,
            'status' => 'completed', 'cash_register_session_id' => $this->session->id, 'created_at' => now()->subHours(2),
        ]);
        SalePayment::create(['sale_id' => $sale->id, 'method' => 'cash', 'amount' => 100]);
    }

    private function register(array $data)
    {
        return $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$this->session->id}/movements", $data + [
            'type' => 'expense', 'description' => 'Compra de frutas en el mercado',
        ]);
    }

    private function stats(): array
    {
        return $this->actingAs($this->cashier)->getJson('/api/cash-sessions/active')->json('stats');
    }

    public function test_split_expense_takes_only_the_cash_part_from_the_drawer(): void
    {
        $this->register(['payment_method' => 'mixed', 'amount' => 100, 'cash_amount' => 40])
            ->assertCreated()
            ->assertJsonPath('movement.payment_method', 'mixed')
            ->assertJsonPath('movement.cash_amount', '40.00')
            ->assertJsonPath('stats.expected_cash', 260)   // 200 + 100 − 40
            ->assertJsonPath('stats.cash_expenses', 40)
            ->assertJsonPath('stats.non_cash_expenses', 60)
            ->assertJsonPath('stats.total_expenses', 100);
    }

    public function test_finance_counts_the_split_expense_once_for_its_total(): void
    {
        $this->register(['payment_method' => 'mixed', 'amount' => 100, 'cash_amount' => 40])->assertCreated();

        $this->assertSame(1, CashMovement::count());
        $this->assertSame(100.0, (float) CashMovement::sole()->amount);
        // Finanzas suma el monto del gasto, sin mirar el método
        $this->assertSame(100.0, (float) CashMovement::where('type', 'expense')->sum('amount'));
    }

    public function test_split_validations(): void
    {
        foreach ([0, 100, 120, -5, null] as $cash) {
            $res = $this->register(['payment_method' => 'mixed', 'amount' => 100, 'cash_amount' => $cash]);
            $res->assertStatus(422);
            $this->assertSame('La parte en efectivo tiene que ser mayor que 0 y menor que el total del gasto.', $res->json('message'), "cash_amount={$cash}");
        }

        $this->register(['type' => 'extra_income', 'payment_method' => 'mixed', 'amount' => 100, 'cash_amount' => 40])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Solo un gasto se puede dividir entre efectivo y QR.');

        // Sin «mixed», cash_amount se ignora
        $this->register(['payment_method' => 'qr', 'amount' => 30, 'cash_amount' => 10])
            ->assertCreated()
            ->assertJsonPath('movement.cash_amount', null)
            ->assertJsonPath('stats.non_cash_expenses', 30);

        $this->assertSame(1, CashMovement::count());
    }

    public function test_correcting_qr_to_split_and_split_to_cash_moves_only_the_cash_difference(): void
    {
        $id = $this->register(['payment_method' => 'qr', 'amount' => 100])->json('movement.id');
        $this->assertSame(300.0, (float) $this->stats()['expected_cash']);

        // QR 100 → 40 efectivo + 60 QR: la gaveta baja 40
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$this->session->id}/movements/{$id}", [
            'reason' => 'Se pagó una parte con efectivo de la gaveta', 'payment_method' => 'mixed', 'cash_amount' => 40,
        ])->assertOk()->assertJsonPath('stats.expected_cash', 260);

        $m = CashMovement::find($id);
        $this->assertSame(['payment_method' => 'qr', 'cash_amount' => null], array_intersect_key($m->edit_previous, ['payment_method' => 1, 'cash_amount' => 1]));
        $log = AuditLog::where('action', 'cash_movement_updated')->latest('id')->first();
        $this->assertStringContainsString('método QR → Efectivo + QR (Bs 40.00 efectivo + Bs 60.00 QR)', $log->description);

        // Cambiar solo el desglose (50 efectivo) cuenta como cambio
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$this->session->id}/movements/{$id}", [
            'reason' => 'Eran 50 en efectivo y 50 por QR', 'payment_method' => 'mixed', 'cash_amount' => 50,
        ])->assertOk()->assertJsonPath('stats.expected_cash', 250);

        // Mixto → todo en efectivo: la gaveta baja el resto
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$this->session->id}/movements/{$id}", [
            'reason' => 'Al final se pagó todo en efectivo', 'payment_method' => 'cash',
        ])->assertOk()->assertJsonPath('stats.expected_cash', 200);
        $this->assertNull(CashMovement::find($id)->cash_amount);

        // Sin cambios (mismo método, mismo monto) → 422
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$this->session->id}/movements/{$id}", [
            'reason' => 'Intento sin ningún cambio real', 'payment_method' => 'cash',
        ])->assertStatus(422)->assertJsonPath('message', 'No hay cambios para guardar.');
    }

    public function test_correcting_a_split_amount_below_its_cash_part_is_rejected(): void
    {
        $id = $this->register(['payment_method' => 'mixed', 'amount' => 100, 'cash_amount' => 40])->json('movement.id');

        // Total 30 con 40 en efectivo ya guardados: no cuadra
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$this->session->id}/movements/{$id}", [
            'reason' => 'El total era menor de lo registrado', 'amount' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors('cash_amount');
    }

    public function test_voiding_a_split_expense_returns_its_cash_part(): void
    {
        $id = $this->register(['payment_method' => 'mixed', 'amount' => 100, 'cash_amount' => 40])->json('movement.id');

        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$this->session->id}/movements/{$id}/void", [
            'reason' => 'La compra se devolvió al proveedor',
        ])->assertOk()->assertJsonPath('stats.expected_cash', 300);

        $this->assertStringContainsString('(Efectivo + QR (Bs 40.00 efectivo + Bs 60.00 QR)) anulado',
            AuditLog::where('action', 'cash_movement_voided')->latest('id')->first()->description);
    }

    public function test_closed_session_correction_to_split_recloses_with_the_same_count(): void
    {
        $id = $this->register(['payment_method' => 'qr', 'amount' => 100])->json('movement.id');
        // Se contaron 260: 40 salieron de la gaveta aunque el gasto quedó como QR → faltante de 40
        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$this->session->id}/close", [
            'actual_closing' => 260, 'cash_withdrawn' => 160, 'cash_left_for_next' => 100,
        ])->assertOk();
        $this->assertSame(-40.0, (float) $this->session->fresh()->difference);

        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->putJson("/api/cash-sessions/{$this->session->id}/movements/{$id}", [
            'reason' => 'Se pagaron 40 con efectivo de la gaveta', 'payment_method' => 'mixed', 'cash_amount' => 40,
        ])->assertOk()
          ->assertJsonPath('closing.expected_before', 300)
          ->assertJsonPath('closing.expected_after', 260)
          ->assertJsonPath('closing.difference_after', 0);

        $this->assertSame(0.0, (float) $this->session->fresh()->difference);
        $this->assertSame(1, CashSessionClosing::where('cash_register_session_id', $this->session->id)->count());
    }

    public function test_report_and_listing_include_the_cash_part(): void
    {
        $this->register(['payment_method' => 'mixed', 'amount' => 100, 'cash_amount' => 40])->assertCreated();

        $this->actingAs($this->cashier)->getJson("/api/cash-sessions/{$this->session->id}/movements")
            ->assertOk()
            ->assertJsonPath('0.payment_method', 'mixed')
            ->assertJsonPath('0.cash_amount', '40.00');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson("/api/cash-sessions/{$this->session->id}/report")
            ->assertOk()
            ->assertJsonPath('movements.0.payment_method', 'mixed')
            ->assertJsonPath('movements.0.cash_amount', 40)
            ->assertJsonPath('summary.cash_expenses', 40)
            ->assertJsonPath('summary.non_cash_expenses', 60);
    }
}
