<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\LoyaltyConfig;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cargar días pasados (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 3): ventas históricas sin stock ni puntos,
 * regla de stock por conteo posterior, N.º de comanda, caja a nombre del cajero y egresos con su fecha.
 */
class PastDaysTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $cashier;
    private Product $bowl;
    private Ingredient $pulp;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 15:00', config('app.timezone')));

        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $this->pulp = Ingredient::create(['name' => 'Pulpa', 'unit' => 'kg', 'current_stock' => 10]);
        $this->bowl = Product::create(['name' => 'Bowl', 'price' => 30, 'category_id' => $category->id]);
        Recipe::create(['product_id' => $this->bowl->id, 'ingredient_id' => $this->pulp->id, 'quantity_required' => 0.2]);
        $this->customer = Customer::create(['name' => 'Ana', 'ci_or_phone' => '7000001', 'loyalty_points' => 0]);
        LoyaltyConfig::create(['accumulation_rate' => 10, 'redemption_value' => 1, 'points_expiration_days' => 365]);

        // Primera venta del POS: el sistema empezó el 1 de octubre
        $first = Sale::create(['id' => (string) Str::uuid(), 'order_number' => '011026-0001', 'daily_sequence' => 1, 'cashier_id' => $this->cashier->id,
            'subtotal' => 30, 'total_amount' => 30, 'status' => 'completed', 'source' => 'pos', 'sync_status' => 'synced']);
        $first->created_at = Carbon::parse('2026-10-01 10:00', config('app.timezone'));
        $first->saveQuietly();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(string $at, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson('/api/admin/sales', array_merge([
            'created_at' => Carbon::parse($at, config('app.timezone'))->toIso8601String(),
            'cashier_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'subtotal' => 30, 'total_amount' => 30, 'edit_reason' => 'Comanda de papel',
            'items' => [['product_id' => $this->bowl->id, 'quantity' => 1, 'unit_price' => 30, 'subtotal' => 30]],
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ], $extra));
    }

    private function stock(): float
    {
        return (float) $this->pulp->fresh()->current_stock;
    }

    private function points(): float
    {
        return (float) $this->customer->fresh()->loyalty_points;
    }

    private function physicalCount(string $at): void
    {
        $id = DB::table('inventory_counts')->insertGetId(['scope' => 'partial', 'counted_at' => Carbon::parse($at, config('app.timezone')), 'lines_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_count_lines')->insert(['inventory_count_id' => $id, 'ingredient_id' => $this->pulp->id, 'expected' => 10, 'counted' => 10, 'difference' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_sale_before_the_system_start_is_historical_without_stock_or_points(): void
    {
        $this->sale('2026-09-15 12:00', ['paper_ref' => '  045 '])->assertCreated()
            ->assertJsonPath('sale.entry_mode', 'historical')
            ->assertJsonPath('sale.paper_ref', '045');

        $this->assertEquals(10, $this->stock());
        $this->assertEquals(0, $this->points());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_sale_create']);
        $this->assertStringContainsString('histórica', \App\Models\AuditLog::where('action', 'admin_sale_create')->latest('id')->value('description'));
    }

    public function test_a_past_day_after_the_start_deducts_stock_and_adds_points(): void
    {
        $this->sale('2026-10-10 12:00')->assertCreated()->assertJsonPath('sale.entry_mode', 'normal');

        $this->assertEquals(9.8, $this->stock());
        $this->assertEquals(3, $this->points());
    }

    public function test_an_ingredient_counted_after_the_sale_is_not_deducted_again(): void
    {
        $this->physicalCount('2026-10-20 18:00');

        $this->sale('2026-10-15 12:00')->assertCreated();
        $this->assertEquals(10, $this->stock());

        $this->sale('2026-10-25 12:00')->assertCreated();
        $this->assertEquals(9.8, $this->stock());
    }

    public function test_voiding_or_editing_a_historical_sale_does_not_take_points_it_never_gave(): void
    {
        $this->customer->update(['loyalty_points' => 50]);
        $id = $this->sale('2026-09-15 12:00')->json('sale.id');

        // Se corrige la fecha: ya no es histórica, ahora descuenta y suma
        $this->actingAs($this->admin)->putJson("/api/admin/sales/{$id}", [
            'created_at' => Carbon::parse('2026-10-12 12:00', config('app.timezone'))->toIso8601String(),
            'customer_id' => $this->customer->id, 'subtotal' => 30, 'total_amount' => 30, 'edit_reason' => 'Fecha mal copiada',
            'items' => [['product_id' => $this->bowl->id, 'quantity' => 1, 'unit_price' => 30, 'subtotal' => 30]],
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();
        $this->assertSame('normal', Sale::find($id)->entry_mode);
        $this->assertEquals(53, $this->points());
        $this->assertEquals(9.8, $this->stock());

        $historical = $this->sale('2026-09-20 12:00')->json('sale.id');
        $this->actingAs($this->admin)->postJson("/api/sales/{$historical}/void", ['void_reason' => 'Duplicada'])->assertOk();
        $this->assertEquals(53, $this->points());
    }

    public function test_day_info_tells_what_will_happen_and_the_owner_can_move_the_start_date(): void
    {
        $this->physicalCount('2026-10-20 18:00');

        $this->actingAs($this->admin)->getJson('/api/admin/sales/day-info?date=2026-09-15')->assertOk()
            ->assertJsonPath('historical', true)->assertJsonPath('system_start_date', '2026-10-01')->assertJsonPath('start_configured', false);
        $this->actingAs($this->admin)->getJson('/api/admin/sales/day-info?date=2026-10-15')->assertOk()
            ->assertJsonPath('historical', false)->assertJsonPath('settled_count', 1);
        $this->actingAs($this->admin)->getJson('/api/admin/sales/day-info?date=2026-10-25')->assertJsonPath('settled_count', 0);

        $this->actingAs($this->admin)->putJson('/api/admin/settings/system-start-date', ['date' => '2026-10-20'])->assertForbidden();
        $this->actingAs($this->owner)->putJson('/api/admin/settings/system-start-date', ['date' => '2026-10-20'])->assertOk()
            ->assertJsonPath('system_start_date', '2026-10-20')->assertJsonPath('start_configured', true);
        $this->actingAs($this->admin)->getJson('/api/admin/sales/day-info?date=2026-10-15')->assertJsonPath('historical', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system_start_date_changed']);

        $this->actingAs($this->owner)->putJson('/api/admin/settings/system-start-date', ['date' => null])->assertOk()
            ->assertJsonPath('system_start_date', '2026-10-01');
    }

    public function test_a_past_day_cash_session_can_be_opened_in_the_cashiers_name(): void
    {
        $this->actingAs($this->admin)->postJson('/api/cash-sessions', ['opening_amount' => 100, 'date' => '2026-10-28', 'cashier_id' => $this->cashier->id])
            ->assertCreated()->assertJsonPath('session.cashier_id', $this->cashier->id);

        $kitchen = User::factory()->create(['role' => 'kitchen']);
        $this->actingAs($this->admin)->postJson('/api/cash-sessions', ['opening_amount' => 100, 'date' => '2026-10-27', 'cashier_id' => $kitchen->id])
            ->assertStatus(422);
    }

    public function test_an_expense_of_a_past_day_keeps_that_date_in_the_session_and_in_finance(): void
    {
        $session = $this->actingAs($this->admin)->postJson('/api/cash-sessions', ['opening_amount' => 100, 'date' => '2026-09-10'])->json('session');

        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$session['id']}/movements", [
            'type' => 'expense', 'amount' => 20, 'description' => 'Servilletas (papel)',
        ])->assertCreated();
        $movement = CashMovement::latest('id')->first();
        $this->assertSame('2026-09-10 23:59:00', $movement->occurred_at->format('Y-m-d H:i:s'));

        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$session['id']}/movements", [
            'type' => 'expense', 'amount' => 5, 'description' => 'Hielo', 'occurred_at' => '2026-09-11T10:00:00-04:00',
        ])->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$session['id']}/movements", [
            'type' => 'expense', 'amount' => 5, 'description' => 'Hielo', 'occurred_at' => '2026-09-10T10:00:00-04:00',
        ])->assertCreated();

        $september = $this->actingAs($this->owner)->getJson('/api/finance/cash-movements?year=2026&month=9')->assertOk();
        $this->assertSame(2, $september->json('count'));
        $this->assertSame(0, $this->actingAs($this->owner)->getJson('/api/finance/cash-movements?year=2026&month=10')->json('count'));
    }

    public function test_an_admin_without_past_days_cannot_open_a_past_day_session(): void
    {
        \App\Models\UserPermission::create(['user_id' => $this->admin->id, 'module' => 'sales_history', 'access_level' => 'none']);
        $this->actingAs($this->admin)->postJson('/api/cash-sessions', ['opening_amount' => 100, 'date' => '2026-10-28'])->assertForbidden();
    }
}
