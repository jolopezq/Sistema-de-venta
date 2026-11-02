<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\LoyaltyConfig;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\DiscountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Descuento manual en caja y en Control diario (docs/PLAN_DESCUENTOS_CAJA.md).
 */
class SaleDiscountTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private User $admin;
    private Product $bowl;
    private Ingredient $acai;

    protected function setUp(): void
    {
        parent::setUp();
        // Mediodía: la venta cae en la caja abierta del día
        $this->travelTo(now()->setTime(12, 0));

        $this->cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Ana']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Lourdes']);

        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $this->acai = Ingredient::create(['name' => 'Acai', 'unit' => 'kg', 'current_stock' => 10]);
        $this->bowl = Product::create(['name' => 'Bowl Grande', 'price' => 50, 'category_id' => $category->id]);
        Recipe::create(['product_id' => $this->bowl->id, 'ingredient_id' => $this->acai->id, 'quantity_required' => 0.2]);
    }

    private function grant(User $user): void
    {
        UserPermission::create(['user_id' => $user->id, 'module' => 'pos_discounts', 'access_level' => 'edit']);
    }

    /** Venta del POS de 3 bowls (Bs 150) con descuento, cobrada en efectivo por el neto. */
    private function syncSale(User $user, float $discount, ?string $reason, array $extra = []): string
    {
        $id = (string) Str::uuid();
        $subtotal = 150.0;
        $total = round($subtotal - $discount, 2);
        $sale = array_merge([
            'id' => $id,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'discount_reason' => $reason,
            'total_amount' => $total,
            'status' => 'completed',
            'source' => 'pos',
            'items' => [['product_id' => $this->bowl->id, 'quantity' => 3, 'unit_price' => 50, 'subtotal' => 150]],
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ], $extra);

        $this->actingAs($user)->postJson('/api/sales/sync', ['sales' => [$sale]])
            ->assertOk()
            ->assertJsonPath('data.synced.0', $id);

        return $id;
    }

    private function auditFor(string $action): ?AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->first();
    }

    // ── Permiso ───────────────────────────────────────────────────────────────

    public function test_nobody_but_the_super_admin_has_the_permission_by_default(): void
    {
        $policy = app(DiscountPolicy::class);
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $kitchen = User::factory()->create(['role' => 'kitchen']);

        $this->assertFalse($policy->allowed($this->cashier));
        $this->assertFalse($policy->allowed($this->admin));
        $this->assertFalse($policy->allowed($kitchen));
        $this->assertTrue($policy->allowed($superAdmin));
    }

    public function test_super_admin_can_grant_the_permission_to_a_cashier_but_not_to_kitchen(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $kitchen = User::factory()->create(['role' => 'kitchen']);

        $this->actingAs($superAdmin)->putJson("/api/users/{$this->cashier->id}/permissions", [
            'permissions' => [['module' => 'pos_discounts', 'access_level' => 'edit']],
        ])->assertOk()->assertJsonPath('modules.pos_discounts.effective', 'edit');

        $this->actingAs($superAdmin)->putJson("/api/users/{$kitchen->id}/permissions", [
            'permissions' => [['module' => 'pos_discounts', 'access_level' => 'edit']],
        ])->assertStatus(422);

        $this->assertTrue(app(DiscountPolicy::class)->allowed($this->cashier->fresh()));
        $this->assertStringContainsString('Descuentos en caja', $this->auditFor('user_permissions_changed')->description);
    }

    public function test_login_and_me_send_the_discount_policy(): void
    {
        $this->grant($this->cashier);

        $this->actingAs($this->cashier)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('discount_policy.allowed', true)
            ->assertJsonPath('discount_policy.max_percent', 20)
            ->assertJsonPath('discount_policy.reason_min', 10)
            ->assertJsonPath('effective_permissions.pos_discounts', 'edit');

        $this->actingAs($this->admin)->getJson('/api/me')
            ->assertJsonPath('discount_policy.allowed', false)
            ->assertJsonPath('discount_policy.max_percent', 100);
    }

    public function test_max_amount_rounds_down_to_the_cent(): void
    {
        $this->assertSame(30.0, DiscountPolicy::maxAmountFor(20, 150));
        $this->assertSame(9.5, DiscountPolicy::maxAmountFor(20, 47.5));
        $this->assertSame(6.69, DiscountPolicy::maxAmountFor(20, 33.47));
        $this->assertSame(47.5, DiscountPolicy::maxAmountFor(100, 47.5));
    }

    // ── Ventas del POS (sincronización) ───────────────────────────────────────

    public function test_cashier_with_permission_discounts_twenty_percent_with_reason(): void
    {
        $this->grant($this->cashier);
        $id = $this->syncSale($this->cashier, 30, '  Pedido de 3 bowls para la oficina  ');

        $this->assertDatabaseHas('sales', [
            'id' => $id,
            'discount_amount' => 30,
            'total_amount' => 120,
            'discount_reason' => 'Pedido de 3 bowls para la oficina',
        ]);
        $log = $this->auditFor('sale_discount_applied');
        $this->assertNotNull($log);
        $this->assertStringContainsString('descuento Bs 30,00 (20 %) sobre Bs 150,00 por Ana', $log->description);
        $this->assertNull($this->auditFor('sale_discount_violation'));
    }

    public function test_cashier_over_twenty_percent_is_saved_and_audited_as_violation(): void
    {
        $this->grant($this->cashier);
        $id = $this->syncSale($this->cashier, 37.5, 'Cliente frecuente del local');

        // Ya se cobró: la venta se guarda igual
        $this->assertDatabaseHas('sales', ['id' => $id, 'discount_amount' => 37.5, 'total_amount' => 112.5]);
        $log = $this->auditFor('sale_discount_violation');
        $this->assertNotNull($log);
        $this->assertStringContainsString('supera el 20 %', $log->description);
    }

    public function test_cashier_without_permission_or_reason_is_audited(): void
    {
        $this->syncSale($this->cashier, 10, 'corto');

        $log = $this->auditFor('sale_discount_violation');
        $this->assertStringContainsString('no tiene el permiso', $log->description);
        $this->assertStringContainsString('motivo de 5 caracteres', $log->description);
    }

    public function test_admin_with_permission_can_discount_half(): void
    {
        $this->grant($this->admin);
        $this->syncSale($this->admin, 75, 'Evento del colegio, 3 bowls');

        $this->assertNotNull($this->auditFor('sale_discount_applied'));
        $this->assertNull($this->auditFor('sale_discount_violation'));
    }

    public function test_admin_courtesy_syncs_with_a_zero_cash_payment(): void
    {
        LoyaltyConfig::create(['accumulation_rate' => 10, 'redemption_value' => 1, 'points_expiration_days' => 365]);
        $customer = Customer::create(['name' => 'Cliente Cortesía', 'ci_or_phone' => '7000001']);
        $this->grant($this->admin);
        $session = CashRegisterSession::create([
            'cashier_id' => $this->admin->id, 'opening_amount' => 100, 'status' => 'open', 'opened_at' => now()->subHour(),
        ]);

        $id = $this->syncSale($this->admin, 150, 'Cortesía por el error del pedido de ayer', ['customer_id' => $customer->id]);

        $sale = Sale::with('payments')->find($id);
        $this->assertSame(0.0, (float) $sale->total_amount);
        $this->assertCount(1, $sale->payments);
        $this->assertSame(0.0, (float) $sale->payments[0]->amount);
        // El producto salió: descuenta inventario; no suma puntos
        $this->assertEqualsWithDelta(9.4, (float) $this->acai->fresh()->current_stock, 0.0001);
        $this->assertSame(0, (int) $customer->fresh()->loyalty_points);
        $this->assertStringContainsString('cortesía (100 %) de Bs 150,00', $this->auditFor('sale_discount_applied')->description);

        $this->actingAs($this->admin)->getJson('/api/cash-sessions/active')
            ->assertOk()
            ->assertJsonPath('stats.expected_cash', 100)
            ->assertJsonPath('stats.discount_count', 1)
            ->assertJsonPath('stats.courtesy_count', 1)
            ->assertJsonPath('stats.total_discounts', 150);
        $this->assertSame($session->id, $sale->cash_register_session_id);
    }

    public function test_cashier_courtesy_is_saved_with_violation(): void
    {
        $this->grant($this->cashier);
        $this->syncSale($this->cashier, 150, 'Le regalé el pedido');

        $this->assertStringContainsString('supera el 20 %', $this->auditFor('sale_discount_violation')->description);
    }

    public function test_pedidosya_order_with_discount_is_audited(): void
    {
        $this->grant($this->admin);
        $this->syncSale($this->admin, 10, 'Promoción de la tablet', [
            'source' => 'pedidosya',
            'payments' => [['method' => 'delivery_platform', 'amount' => 140]],
        ]);

        $this->assertStringContainsString('PedidosYa', $this->auditFor('sale_discount_violation')->description);
    }

    public function test_reason_is_dropped_when_there_is_no_discount(): void
    {
        $id = $this->syncSale($this->cashier, 0, 'No debería guardarse');

        $this->assertNull(Sale::find($id)->discount_reason);
        $this->assertSame(0, AuditLog::whereIn('action', ['sale_discount_applied', 'sale_discount_violation'])->count());
    }

    // ── Arqueo y reporte ──────────────────────────────────────────────────────

    public function test_session_stats_and_report_list_the_discounts(): void
    {
        $this->grant($this->cashier);
        $session = CashRegisterSession::create([
            'cashier_id' => $this->cashier->id, 'opening_amount' => 100, 'status' => 'open', 'opened_at' => now()->subHour(),
        ]);
        $this->syncSale($this->cashier, 30, 'Pedido de 3 bowls para la oficina');
        $this->syncSale($this->cashier, 0, null);
        // Un recargo viejo del editor (descuento negativo) no resta de los descuentos
        Sale::create([
            'id' => (string) Str::uuid(), 'cashier_id' => $this->cashier->id, 'subtotal' => 40,
            'discount_amount' => -5, 'total_amount' => 45, 'status' => 'completed', 'created_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($this->cashier)->getJson('/api/cash-sessions/active')
            ->assertJsonPath('stats.total_discounts', 30)
            ->assertJsonPath('stats.discount_count', 1)
            ->assertJsonPath('stats.courtesy_count', 0)
            // 100 de fondo + 120 + 150 en efectivo (el recargo viejo no tiene pagos)
            ->assertJsonPath('stats.expected_cash', 370);

        $report = $this->actingAs($this->admin)->getJson("/api/cash-sessions/{$session->id}/report")->assertOk();
        $report->assertJsonCount(1, 'discounts')
            ->assertJsonPath('discounts.0.cashier', 'Ana')
            ->assertJsonPath('discounts.0.discount_amount', 30)
            ->assertJsonPath('discounts.0.percent', 20)
            ->assertJsonPath('discounts.0.is_courtesy', false)
            ->assertJsonPath('discounts.0.discount_reason', 'Pedido de 3 bowls para la oficina');
    }

    public function test_sales_index_summary_counts_discounts(): void
    {
        $this->grant($this->cashier);
        $this->syncSale($this->cashier, 30, 'Pedido de 3 bowls para la oficina');

        $this->actingAs($this->admin)->getJson('/api/sales?date=' . now()->toDateString())
            ->assertOk()
            ->assertJsonPath('summary.total_discounts', 30)
            ->assertJsonPath('summary.discount_count', 1)
            ->assertJsonPath('summary.courtesy_count', 0);
    }

    // ── Control diario (editor) ──────────────────────────────────────────────

    private function adminPayload(float $discount, ?string $reason, array $extra = []): array
    {
        $total = round(150 - $discount, 2);

        return array_merge([
            'created_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'subtotal' => 150,
            'discount_amount' => $discount,
            'discount_reason' => $reason,
            'total_amount' => $total,
            'source' => 'pos',
            'edit_reason' => 'Carga de la comanda en papel',
            'items' => [['product_id' => $this->bowl->id, 'quantity' => 3, 'unit_price' => 50, 'subtotal' => 150]],
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ], $extra);
    }

    public function test_control_diario_requires_the_permission_for_a_new_discount(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/sales', $this->adminPayload(20, 'Cliente frecuente del local'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_amount');

        $this->grant($this->admin);
        $res = $this->actingAs($this->admin)->postJson('/api/admin/sales', $this->adminPayload(20, 'Cliente frecuente del local'))
            ->assertCreated();

        $this->assertSame('Cliente frecuente del local', $res->json('sale.discount_reason'));
        $this->assertStringContainsString('(Control diario)', $this->auditFor('sale_discount_applied')->description);
    }

    public function test_control_diario_requires_the_reason(): void
    {
        $this->grant($this->admin);

        $this->actingAs($this->admin)->postJson('/api/admin/sales', $this->adminPayload(20, 'corto'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_reason');
    }

    public function test_control_diario_allows_an_admin_courtesy_with_a_zero_payment(): void
    {
        $this->grant($this->admin);

        $res = $this->actingAs($this->admin)
            ->postJson('/api/admin/sales', $this->adminPayload(150, 'Cortesía al proveedor de frutas'))
            ->assertCreated();

        $this->assertSame(0.0, (float) $res->json('sale.total_amount'));

        // Un pago de Bs 0 en una venta que sí se cobra sigue rechazado
        $payload = $this->adminPayload(0, null);
        $payload['payments'] = [['method' => 'cash', 'amount' => 150], ['method' => 'qr', 'amount' => 0]];
        $this->actingAs($this->admin)->postJson('/api/admin/sales', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('payments.1.amount');
    }

    public function test_control_diario_rejects_a_discount_on_pedidosya(): void
    {
        $this->grant($this->admin);
        $payload = $this->adminPayload(10, 'Promoción de la tablet', [
            'source' => 'pedidosya',
            'payments' => [['method' => 'delivery_platform', 'amount' => 140]],
        ]);

        $this->actingAs($this->admin)->postJson('/api/admin/sales', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_amount');
    }

    public function test_editing_other_data_of_a_discounted_sale_does_not_need_the_permission(): void
    {
        $this->grant($this->cashier);
        $id = $this->syncSale($this->cashier, 30, 'Pedido de 3 bowls para la oficina');

        // El admin no tiene el permiso, pero no cambia el descuento: solo la nota
        $this->actingAs($this->admin)->putJson("/api/admin/sales/{$id}", $this->adminPayload(30, 'Pedido de 3 bowls para la oficina', [
            'notes' => 'Retiraron en la puerta',
        ]))->assertOk();
        $this->assertSame('Retiraron en la puerta', Sale::find($id)->notes);

        // Cambiar el monto sí lo exige
        $this->actingAs($this->admin)->putJson("/api/admin/sales/{$id}", $this->adminPayload(40, 'Pedido de 3 bowls para la oficina'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_amount');

        // Quitar el descuento borra el motivo
        $this->actingAs($this->admin)->putJson("/api/admin/sales/{$id}", $this->adminPayload(0, 'Pedido de 3 bowls para la oficina'))
            ->assertOk();
        $this->assertNull(Sale::find($id)->discount_reason);
    }
}
