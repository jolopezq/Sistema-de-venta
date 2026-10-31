<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\UserPermission;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Corregir cajas cerradas: «Reabrir para corregir» (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 4).
 */
class ReopenCashSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $cashier;
    private CashRegisterSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 15:00', config('app.timezone')));
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Lourdes']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $product = Product::create(['name' => 'Bowl', 'price' => 30, 'category_id' => $category->id]);

        // Caja del 28: fondo 100 + venta en efectivo de 30; el cajero escribió 13 en vez de 130
        $this->session = CashRegisterSession::create([
            'cashier_id' => $this->cashier->id, 'opening_amount' => 100, 'status' => 'open',
            'opened_at' => Carbon::parse('2026-10-28 09:00', config('app.timezone')),
        ]);
        $this->actingAs($this->cashier)->postJson('/api/sales/sync', ['sales' => [[
            'id' => (string) Str::uuid(), 'subtotal' => 30, 'total_amount' => 30,
            'created_at' => Carbon::parse('2026-10-28 12:00', config('app.timezone'))->utc()->toIso8601String(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 30, 'subtotal' => 30]],
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ]]])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-28 21:00', config('app.timezone')));
        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$this->session->id}/close", [
            'actual_closing' => 13, 'cash_withdrawn' => 13, 'cash_left_for_next' => 0, 'diff_note' => 'No sé qué pasó',
        ])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-31 15:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function grantCorrections(): void
    {
        UserPermission::create(['user_id' => $this->admin->id, 'module' => 'register_corrections', 'access_level' => 'edit']);
    }

    public function test_reopening_needs_the_permission_and_a_reason(): void
    {
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$this->session->id}/reopen", ['reason' => 'Error de tipeo en el conteo'])
            ->assertForbidden();
        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$this->session->id}/reopen", ['reason' => 'Error de tipeo en el conteo'])
            ->assertForbidden();

        $this->grantCorrections();
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$this->session->id}/reopen", ['reason' => 'corto'])
            ->assertStatus(422);
    }

    public function test_reopen_keeps_the_previous_closing_and_reclose_corrects_it_with_its_original_date(): void
    {
        $this->grantCorrections();
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$this->session->id}/reopen", ['reason' => 'El cajero escribió 13 en vez de 130'])
            ->assertOk()->assertJsonPath('session.status', 'open');
        $this->assertDatabaseHas('audit_logs', ['action' => 'cash_session_reopened']);
        $this->assertNotNull($this->session->fresh()->reopened_at);

        // Sin motivo de corrección no cierra
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$this->session->id}/close", [
            'actual_closing' => 130, 'cash_withdrawn' => 130, 'cash_left_for_next' => 0,
        ])->assertStatus(422);

        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$this->session->id}/close", [
            'actual_closing' => 130, 'cash_withdrawn' => 130, 'cash_left_for_next' => 0,
            'correction_reason' => 'Conteo corregido según la hoja firmada',
        ])->assertOk();

        $fresh = $this->session->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertNull($fresh->reopened_at);
        $this->assertEquals(0, $fresh->difference);
        $this->assertSame('2026-10-28 21:00', $fresh->closed_at->format('Y-m-d H:i'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'cash_session_reclosed']);

        $report = $this->actingAs($this->owner)->getJson("/api/cash-sessions/{$this->session->id}/report")->assertOk();
        $this->assertCount(1, $report->json('closings'));
        $this->assertEquals(-117, $report->json('closings.0.difference'));
        $this->assertSame('Lourdes', $report->json('closings.0.reopened_by'));
        $this->assertSame('Conteo corregido según la hoja firmada', $report->json('closings.0.reclose_reason'));
        $this->assertSame('El cajero escribió 13 en vez de 130', $report->json('closings.0.reopen_reason'));
    }

    public function test_a_reopened_past_day_session_is_not_seen_by_the_pos_and_takes_no_new_sales(): void
    {
        $this->grantCorrections();
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$this->session->id}/reopen", ['reason' => 'Falta un egreso de servilletas'])->assertOk();

        $this->actingAs($this->cashier)->getJson('/api/cash-sessions/active')->assertJsonPath('session', null);
        $resolved = app(\App\Services\CashSessionResolver::class)->resolve($this->cashier->id, now());
        $this->assertNull($resolved);

        // El egreso olvidado queda con la fecha de ese día y baja el teórico
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$this->session->id}/movements", [
            'type' => 'expense', 'amount' => 20, 'description' => 'Servilletas',
        ])->assertCreated();
        $this->assertSame('2026-10-28', \App\Models\CashMovement::latest('id')->first()->occurred_at->toDateString());
    }

    public function test_reclosing_a_reopened_session_needs_the_corrections_permission(): void
    {
        $this->grantCorrections();
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$this->session->id}/reopen", ['reason' => 'Error de tipeo en el conteo'])->assertOk();

        // Otro admin, sin el permiso, no puede cerrarla aunque vea cajas de otros
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other)->postJson("/api/cash-sessions/{$this->session->id}/close", [
            'actual_closing' => 130, 'cash_withdrawn' => 130, 'cash_left_for_next' => 0, 'correction_reason' => 'Lo cierro yo sin permiso',
        ])->assertForbidden();
    }

    public function test_the_report_warns_when_the_next_session_opened_with_another_fund(): void
    {
        CashRegisterSession::create([
            'cashier_id' => $this->cashier->id, 'opening_amount' => 50, 'status' => 'open',
            'opened_at' => Carbon::parse('2026-10-29 09:00', config('app.timezone')),
        ]);

        $this->actingAs($this->owner)->getJson("/api/cash-sessions/{$this->session->id}/report")
            ->assertJsonPath('chain.next_opening_amount', 50)
            ->assertJsonPath('chain.left_for_next', 0)
            ->assertJsonPath('chain.matches', false);
    }

    public function test_only_closed_sessions_can_be_reopened(): void
    {
        $this->grantCorrections();
        $open = CashRegisterSession::create(['cashier_id' => $this->cashier->id, 'opening_amount' => 0, 'status' => 'open', 'opened_at' => now()]);
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$open->id}/reopen", ['reason' => 'No corresponde reabrir'])->assertStatus(422);
    }
}
