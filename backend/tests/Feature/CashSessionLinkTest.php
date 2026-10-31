<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashSessionLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 15:00:00', config('app.timezone')));

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $this->product = Product::create(['name' => 'Bowl', 'price' => 30, 'category_id' => $category->id, 'printer_target' => 'kitchen']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sale_counts_only_in_one_session_when_cashier_and_admin_both_have_open_sessions(): void
    {
        $cashierSession = $this->openSession($this->cashier, now()->subHours(2));
        $adminSession = $this->openSession($this->admin, now()->subHours(3));

        $this->syncSale(now()->subHour());

        $this->actingAs($this->cashier)->getJson("/api/cash-sessions/{$cashierSession->id}")->assertJsonPath('stats.cash_sales', 30);
        $this->actingAs($this->admin)->getJson("/api/cash-sessions/{$adminSession->id}")->assertJsonPath('stats.cash_sales', 0);
    }

    public function test_cashier_sale_falls_back_to_shared_admin_session(): void
    {
        $adminSession = $this->openSession($this->admin, now()->subHours(3));

        $id = $this->syncSale(now()->subHour());

        $this->assertSame($adminSession->id, Sale::find($id)->cash_register_session_id);
    }

    public function test_admin_sale_goes_into_the_open_cashier_session_and_counts_in_its_arqueo(): void
    {
        $cashierSession = $this->openSession($this->cashier, now()->subHours(2));

        $id = $this->syncSale(now()->subHour(), $this->admin);

        $this->assertSame($cashierSession->id, Sale::find($id)->cash_register_session_id);
        $this->actingAs($this->cashier)->getJson("/api/cash-sessions/{$cashierSession->id}")->assertJsonPath('stats.cash_sales', 30);
    }

    public function test_admin_sale_prefers_the_admins_own_session(): void
    {
        $this->openSession($this->cashier, now()->subHours(2));
        $adminSession = $this->openSession($this->admin, now()->subHours(3));

        $id = $this->syncSale(now()->subHour(), $this->admin);

        $this->assertSame($adminSession->id, Sale::find($id)->cash_register_session_id);
    }

    public function test_session_left_open_yesterday_does_not_absorb_todays_sales(): void
    {
        $this->openSession($this->cashier, now()->subDay());

        $id = $this->syncSale(now()->subHour());

        $this->assertNull(Sale::find($id)->cash_register_session_id);
    }

    public function test_late_sync_into_closed_session_keeps_stored_arqueo_and_is_audited(): void
    {
        $session = $this->openSession($this->cashier, now()->subHours(3));
        $session->update(['status' => 'closed', 'closed_at' => now()->subHour(), 'expected_closing' => 100]);

        $id = $this->syncSale(now()->subHours(2));

        $this->assertSame($session->id, Sale::find($id)->cash_register_session_id);
        $this->assertEquals(100, $session->fresh()->expected_closing);
        $this->assertDatabaseHas('audit_logs', ['action' => 'closed_session_sale_change']);
    }

    public function test_pos_utc_timestamp_is_stored_in_app_timezone(): void
    {
        $id = $this->syncSale(Carbon::parse('2026-10-04 13:30:00', config('app.timezone')));

        $this->assertSame('2026-10-04 13:30:00', Sale::find($id)->getRawOriginal('created_at'));
    }

    public function test_admin_edit_changing_date_moves_sale_to_matching_session(): void
    {
        $yesterday = $this->openSession($this->admin, now()->subDay()->setTime(9, 0));
        $yesterday->update(['status' => 'closed', 'closed_at' => now()->subDay()->setTime(20, 0)]);
        $today = $this->openSession($this->admin, now()->subHours(3));

        $id = $this->syncSale(now()->subHour());
        $this->assertSame($today->id, Sale::find($id)->cash_register_session_id);

        $this->actingAs($this->admin)->putJson("/api/admin/sales/{$id}", [
            'created_at' => now()->subDay()->setTime(12, 0)->toIso8601String(),
            'subtotal' => 30, 'total_amount' => 30, 'edit_reason' => 'Fecha corregida',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 30, 'subtotal' => 30]],
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        $this->assertSame($yesterday->id, Sale::find($id)->cash_register_session_id);
    }

    public function test_opening_backdated_session_attaches_orphan_sales_of_that_day(): void
    {
        $id = $this->syncSale(now()->subDays(2)->setTime(12, 0));
        $early = $this->syncSale(now()->subDays(2)->setTime(4, 12));
        $this->assertNull(Sale::find($id)->cash_register_session_id);

        $response = $this->actingAs($this->admin)->postJson('/api/cash-sessions', [
            'opening_amount' => 100,
            'date' => now()->subDays(2)->format('Y-m-d'),
        ])->assertCreated();

        $this->assertSame($response->json('session.id'), Sale::find($id)->cash_register_session_id);
        // La caja retroactiva cubre el día completo: incluye ventas de madrugada
        $this->assertSame($response->json('session.id'), Sale::find($early)->cash_register_session_id);
        $response->assertJsonPath('stats.cash_sales', 60);
    }

    public function test_backdated_session_closed_weeks_later_only_takes_sales_of_its_own_day(): void
    {
        // Caja del 1 de octubre abierta desde Control diario y cerrada el 4 (como las cajas #9 y #10 del VPS)
        $response = $this->actingAs($this->admin)->postJson('/api/cash-sessions', [
            'opening_amount' => 0, 'date' => '2026-10-01',
        ])->assertCreated();
        $backdated = CashRegisterSession::find($response->json('session.id'));
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$backdated->id}/close", [
            'actual_closing' => 0, 'cash_withdrawn' => 0, 'cash_left_for_next' => 0,
        ])->assertOk();
        $this->assertTrue($backdated->fresh()->closed_at->isSameDay(now()));

        $sameDay = $this->syncSale(Carbon::parse('2026-10-01 18:00', config('app.timezone')));
        $twoDaysLater = $this->syncSale(Carbon::parse('2026-10-03 12:00', config('app.timezone')), $this->admin);

        $this->assertSame($backdated->id, Sale::find($sameDay)->cash_register_session_id);
        $this->assertNull(Sale::find($twoDaysLater)->cash_register_session_id);
    }

    public function test_backdated_sale_registered_in_control_diario_goes_to_its_own_days_session(): void
    {
        // Caja retroactiva del 1 (cerrada hoy) y caja real del 2: una venta cargada para el 2 va a la del 2
        $first = $this->openSession($this->admin, Carbon::parse('2026-10-01 00:00', config('app.timezone')));
        $first->update(['status' => 'closed', 'closed_at' => now()]);
        $second = $this->openSession($this->cashier, Carbon::parse('2026-10-02 09:00', config('app.timezone')));
        $second->update(['status' => 'closed', 'closed_at' => Carbon::parse('2026-10-02 21:00', config('app.timezone'))]);

        $this->actingAs($this->admin)->postJson('/api/admin/sales', [
            'created_at' => Carbon::parse('2026-10-02 15:00', config('app.timezone'))->toIso8601String(),
            'cashier_id' => $this->admin->id,
            'subtotal' => 30, 'total_amount' => 30, 'edit_reason' => 'Comanda de papel',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 30, 'subtotal' => 30]],
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertCreated();

        $this->assertSame($second->id, Sale::latest('created_at')->first()->cash_register_session_id);
    }

    public function test_report_of_closed_session_keeps_saved_closing_and_shows_it_recalculated_after_an_edit(): void
    {
        $session = $this->openSession($this->cashier, now()->subHours(3));
        $id = $this->syncSale(now()->subHours(2));
        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$session->id}/close", [
            'actual_closing' => 30, 'cash_withdrawn' => 30, 'cash_left_for_next' => 0,
        ])->assertOk();

        $this->actingAs($this->admin)->getJson("/api/cash-sessions/{$session->id}/report")
            ->assertOk()
            ->assertJsonPath('recalculated.changed', false)
            ->assertJsonPath('recalculated.changes', []);

        // Se cobró con QR, no en efectivo: el teórico de esa caja baja a 0 y aparece un sobrante de Bs 30
        $this->actingAs($this->admin)->putJson("/api/admin/sales/{$id}", [
            'created_at' => now()->subHours(2)->toIso8601String(),
            'subtotal' => 30, 'total_amount' => 30, 'edit_reason' => 'Era QR',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 30, 'subtotal' => 30]],
            'payments' => [['method' => 'qr', 'amount' => 30]],
        ])->assertOk();

        $report = $this->actingAs($this->admin)->getJson("/api/cash-sessions/{$session->id}/report")->assertOk();
        $this->assertEquals(30, $report->json('expected_closing'));
        $this->assertEquals(0, $report->json('difference'));
        $this->assertTrue($report->json('recalculated.changed'));
        $this->assertEquals(0, $report->json('recalculated.expected_closing'));
        $this->assertEquals(30, $report->json('recalculated.difference'));
        $this->assertStringStartsWith('Edición de venta Ticket #', $report->json('recalculated.changes.0.description'));
        $this->assertSame($this->admin->name, $report->json('recalculated.changes.0.user'));
        $this->assertEquals(30, $session->fresh()->expected_closing);
    }

    public function test_report_of_open_session_has_no_recalculated_block(): void
    {
        $session = $this->openSession($this->cashier, now()->subHour());

        $this->actingAs($this->cashier)->getJson("/api/cash-sessions/{$session->id}/report")
            ->assertOk()
            ->assertJsonPath('recalculated', null);
    }

    private function openSession(User $user, Carbon $openedAt): CashRegisterSession
    {
        return CashRegisterSession::create([
            'cashier_id' => $user->id, 'opening_amount' => 0, 'status' => 'open', 'opened_at' => $openedAt,
        ]);
    }

    private function syncSale(Carbon $at, ?User $seller = null): string
    {
        $id = (string) Str::uuid();

        $this->actingAs($seller ?? $this->cashier)->postJson('/api/sales/sync', ['sales' => [[
            'id' => $id,
            'subtotal' => 30,
            'total_amount' => 30,
            'created_at' => $at->copy()->utc()->toIso8601String(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 30, 'subtotal' => 30]],
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ]]])->assertOk()->assertJsonPath('data.synced.0', $id);

        return $id;
    }
}
