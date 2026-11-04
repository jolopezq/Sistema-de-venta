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

/**
 * Cajas: una venta de un día pasado entra en la caja de su día, aviso de cajas sin cerrar y motivo cuando el fondo
 * no coincide con lo que dejó la caja anterior (docs/PLAN_CONCILIACION_CAJA.md, C, D y E).
 */
class CashReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $cashier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-04 15:00:00', config('app.timezone')));
        $this->owner = User::factory()->create(['role' => 'super_admin', 'name' => 'Josue']);
        $this->cashier = User::factory()->create(['role' => 'admin', 'name' => 'Lourdes']);
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);
        $this->product = Product::create(['name' => 'Açaí Simple', 'price' => 14, 'category_id' => $category->id, 'printer_target' => 'kitchen']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $when): Carbon
    {
        return Carbon::parse($when, config('app.timezone'));
    }

    private function caja(User $user, string $openedAt, ?array $close = null): CashRegisterSession
    {
        return CashRegisterSession::create([
            'cashier_id' => $user->id, 'opening_amount' => $close[3] ?? 250, 'status' => $close ? 'closed' : 'open',
            'opened_at' => $openedAt, 'closed_at' => $close[0] ?? null,
            'actual_closing' => $close ? $close[1] + $close[2] : null, 'cash_withdrawn' => $close[1] ?? null, 'cash_left_for_next' => $close[2] ?? null,
        ]);
    }

    public function test_una_venta_cargada_en_control_diario_antes_de_la_apertura_entra_en_la_caja_de_ese_dia(): void
    {
        // Como la 171026-0005: caja del 17/10 abierta a las 15:31 y la venta cargada con la hora del reloj (02:57)
        $caja = $this->caja($this->cashier, '2026-10-17 15:31:00', ['2026-11-02 12:37:00', 50, 272]);

        $this->actingAs($this->owner)->postJson('/api/admin/sales', [
            'created_at' => $this->at('2026-10-17 02:57')->toIso8601String(),
            'subtotal' => 280, 'discount_amount' => 20, 'discount_reason' => 'Clientes especial', 'total_amount' => 260,
            'edit_reason' => 'Registro fuera de fecha',
            'items' => [['product_id' => $this->product->id, 'quantity' => 20, 'unit_price' => 14, 'subtotal' => 280]],
            'payments' => [['method' => 'qr', 'amount' => 260]],
        ])->assertCreated();

        $this->assertSame($caja->id, Sale::latest('created_at')->first()->cash_register_session_id);
    }

    public function test_una_venta_sin_conexion_anterior_a_la_apertura_entra_en_la_caja_de_hoy(): void
    {
        $id = (string) Str::uuid();
        $this->actingAs($this->cashier)->postJson('/api/sales/sync', ['sales' => [[
            'id' => $id, 'subtotal' => 14, 'total_amount' => 14,
            'created_at' => $this->at('2026-11-04 14:10')->utc()->toIso8601String(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 14, 'subtotal' => 14]],
            'payments' => [['method' => 'cash', 'amount' => 14]],
        ]]])->assertOk();
        $this->assertNull(Sale::find($id)->cash_register_session_id);

        $response = $this->actingAs($this->cashier)->postJson('/api/cash-sessions', ['opening_amount' => 0])->assertCreated();

        $this->assertSame($response->json('session.id'), Sale::find($id)->cash_register_session_id);
        $response->assertJsonPath('stats.cash_sales', 14);
    }

    public function test_abrir_con_un_fondo_distinto_al_que_quedo_pide_el_motivo(): void
    {
        $this->caja($this->cashier, '2026-11-03 15:00:00', ['2026-11-03 22:00:00', 300, 249]);

        $this->actingAs($this->cashier)->postJson('/api/cash-sessions', ['opening_amount' => 253])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'opening_mismatch')
            ->assertJsonPath('expected', 249)
            ->assertJsonPath('previous_date', '2026-11-03');

        $response = $this->actingAs($this->cashier)->postJson('/api/cash-sessions', [
            'opening_amount' => 253, 'opening_note' => 'Encontré 4 Bs en monedas',
        ])->assertCreated();

        $this->assertSame('Encontré 4 Bs en monedas', CashRegisterSession::find($response->json('session.id'))->opening_note);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cash_session_opening_mismatch']);
    }

    public function test_abrir_con_lo_que_dejo_la_caja_anterior_no_pide_motivo(): void
    {
        $this->caja($this->cashier, '2026-11-03 15:00:00', ['2026-11-03 22:00:00', 300, 249]);

        $response = $this->actingAs($this->cashier)->postJson('/api/cash-sessions', ['opening_amount' => 249])->assertCreated();

        $this->assertNull(CashRegisterSession::find($response->json('session.id'))->opening_note);
    }

    public function test_una_caja_de_un_dia_pasado_se_compara_con_la_cerrada_antes_de_ese_dia(): void
    {
        // Como el 03/10: la del 02/10 dejó 244 y se quiso abrir con 0 (el fondo iba a ir como «ingreso extra»)
        $this->caja($this->owner, '2026-10-02 00:00:00', ['2026-10-24 14:38:00', 200, 244]);
        $this->caja($this->cashier, '2026-10-20 16:20:00', ['2026-10-20 22:46:00', 200, 230]);

        $this->actingAs($this->owner)->postJson('/api/cash-sessions', ['opening_amount' => 0, 'date' => '2026-10-03'])
            ->assertStatus(422)
            ->assertJsonPath('expected', 244);

        $this->actingAs($this->owner)->getJson('/api/cash-sessions/opening-info?date=2026-10-03')
            ->assertOk()
            ->assertJsonPath('suggested_opening', 244)
            ->assertJsonPath('previous.date', '2026-10-02');
    }

    public function test_el_qr_de_la_app_del_banco_se_anota_al_cerrar_y_se_compara_en_finanzas(): void
    {
        Carbon::setTestNow($this->at('2026-10-22 22:30'));
        $caja = $this->caja($this->cashier, '2026-10-22 15:23:00');
        $caja->update(['opening_amount' => 0]);
        foreach ([[30, 'qr'], [30, 'qr'], [37, 'cash']] as [$amount, $method]) {
            $id = (string) Str::uuid();
            $this->actingAs($this->cashier)->postJson('/api/sales/sync', ['sales' => [[
                'id' => $id, 'subtotal' => $amount, 'total_amount' => $amount,
                'created_at' => now()->subHour()->utc()->toIso8601String(),
                'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => $amount, 'subtotal' => $amount]],
                'payments' => [['method' => $method, 'amount' => $amount]],
            ]]])->assertOk();
        }

        // La app del banco muestra 30: falta un cobro QR de 30
        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$caja->id}/close", [
            'actual_closing' => 37, 'cash_withdrawn' => 37, 'cash_left_for_next' => 0, 'qr_bank_reported' => 30,
        ])->assertOk();
        $this->assertEquals(30, $caja->fresh()->qr_bank_reported);

        $this->actingAs($this->owner)->getJson("/api/cash-sessions/{$caja->id}/report")
            ->assertOk()
            ->assertJsonPath('qr_bank_reported', 30)
            ->assertJsonPath('qr_bank_difference', -30);

        Carbon::setTestNow($this->at('2026-11-04 15:00'));
        $pnl = $this->actingAs($this->owner)->getJson('/api/finance/pnl?year=2026&month=10')->assertOk()->json();
        $qr = $pnl['cash_in']['drawer']['qr_bank'];
        $this->assertSame(1, $qr['reported_count']);
        $this->assertEquals(60, $qr['system']);
        $this->assertEquals(30, $qr['bank']);
        $this->assertEquals(-30, $qr['difference']);
        $warning = collect($pnl['warnings'])->firstWhere('code', 'qr_bank_difference');
        $this->assertStringContainsString('22/10 −Bs 30,00', $warning['message']);
        $this->assertSame('2026-10-22', $warning['date']);
    }

    public function test_al_corregir_un_cierre_sin_volver_a_anotar_el_qr_se_conserva_el_anterior(): void
    {
        $caja = $this->caja($this->owner, '2026-11-04 09:00:00');
        $caja->update(['opening_amount' => 0]);
        $this->actingAs($this->owner)->postJson("/api/cash-sessions/{$caja->id}/close", [
            'actual_closing' => 0, 'cash_withdrawn' => 0, 'cash_left_for_next' => 0, 'qr_bank_reported' => 45,
        ])->assertOk();

        $this->actingAs($this->owner)->postJson("/api/cash-sessions/{$caja->id}/reopen", ['reason' => 'Faltó un egreso del papel'])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/cash-sessions/{$caja->id}/close", [
            'actual_closing' => 0, 'cash_withdrawn' => 0, 'cash_left_for_next' => 0, 'correction_reason' => 'Se corrigió el egreso del papel',
        ])->assertOk();

        $this->assertEquals(45, $caja->fresh()->qr_bank_reported);
    }

    public function test_avisa_las_cajas_de_dias_anteriores_que_siguen_abiertas(): void
    {
        $this->caja($this->cashier, '2026-10-14 17:21:00');
        $this->caja($this->owner, '2026-10-27 15:38:00');
        $this->caja($this->owner, '2026-11-04 09:00:00');   // la de hoy no cuenta

        $all = $this->actingAs($this->owner)->getJson('/api/cash-sessions/opening-info')->assertOk()->json('unclosed');
        $this->assertSame(['2026-10-14', '2026-10-27'], array_column($all, 'date'));
        $this->assertSame('Lourdes', $all[0]['cashier']);

        // Quien no ve cajas ajenas solo ve las suyas
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->caja($cashier, '2026-10-30 15:00:00');
        $own = $this->actingAs($cashier)->getJson('/api/cash-sessions/opening-info')->assertOk()->json('unclosed');
        $this->assertSame(['2026-10-30'], array_column($own, 'date'));

        // Al abrir la caja de hoy, la respuesta también las trae
        $this->actingAs($cashier)->postJson('/api/cash-sessions', ['opening_amount' => 0])
            ->assertCreated()
            ->assertJsonPath('unclosed.0.date', '2026-10-30');
    }
}
