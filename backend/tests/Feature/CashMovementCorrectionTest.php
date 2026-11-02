<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashMovementCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected User $admin;
    protected User $otherCashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create([
            'role' => 'cashier',
            'name' => 'Ana Cajera',
        ]);
        $this->otherCashier = User::factory()->create([
            'role' => 'cashier',
            'name' => 'Beto Cajero',
        ]);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Carlos Admin',
        ]);
    }

    /**
     * El caso real:
     * - Fondo 294.00 y ventas en efectivo 102.00
     * - Gasto de 74.00 registrado como QR -> gaveta 396.00
     * - Corregido a Efectivo -> gaveta 322.00
     * - Guarda edit_previous, edit_reason, edit_count, edited_by y auditoría
     */
    public function test_real_case_correct_qr_expense_to_cash_recalculates_drawer_and_audits(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id'     => $this->cashier->id,
            'opening_amount' => 294.00,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        // Venta en efectivo de 102.00
        $sale = Sale::create([
            'id'                       => (string) Str::uuid(),
            'cashier_id'               => $this->cashier->id,
            'subtotal'                 => 102.00,
            'total_amount'             => 102.00,
            'status'                   => 'completed',
            'cash_register_session_id' => $session->id,
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'method'  => 'cash',
            'amount'  => 102.00,
        ]);

        // Registrar egreso QR de 74.00
        $regResponse = $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$session->id}/movements", [
            'type'           => 'expense',
            'payment_method' => 'qr',
            'amount'         => 74.00,
            'description'    => 'Compra de frutas mercado',
        ]);
        $regResponse->assertStatus(201);
        $movementId = $regResponse->json('movement.id');

        // Estado inicial de la gaveta: 294 + 102 - 0 = 396.00
        $this->assertEquals(396.00, $regResponse->json('stats.expected_cash'));
        $this->assertEquals(0.00, $regResponse->json('stats.cash_expenses'));
        $this->assertEquals(74.00, $regResponse->json('stats.non_cash_expenses'));

        // Corregir a efectivo
        $updateResponse = $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$session->id}/movements/{$movementId}", [
            'reason'         => 'Se pagó en efectivo de la gaveta, error de dedo al registrar',
            'payment_method' => 'cash',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('message', 'Movimiento de caja corregido correctamente.')
            ->assertJsonPath('movement.payment_method', 'cash')
            ->assertJsonPath('movement.edit_reason', 'Se pagó en efectivo de la gaveta, error de dedo al registrar')
            ->assertJsonPath('movement.edit_count', 1)
            ->assertJsonPath('movement.edited_by.id', $this->cashier->id);

        // Estado recalculado de la gaveta: 294 + 102 - 74 = 322.00
        $this->assertEquals(322.00, $updateResponse->json('stats.expected_cash'));
        $this->assertEquals(74.00, $updateResponse->json('stats.cash_expenses'));
        $this->assertEquals(0.00, $updateResponse->json('stats.non_cash_expenses'));

        // Verificar persistencia de edit_previous en base de datos
        $movement = CashMovement::findOrFail($movementId);
        $this->assertEquals('cash', $movement->payment_method);
        $this->assertEquals('qr', $movement->edit_previous['payment_method']);
        $this->assertEquals(74.00, $movement->edit_previous['amount']);

        // Verificar log de auditoría
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->cashier->id,
            'action'  => 'cash_movement_updated',
            'module'  => 'CashMovement',
        ]);
        $log = AuditLog::where('action', 'cash_movement_updated')->latest('id')->first();
        $this->assertStringContainsString('método QR → Efectivo', $log->description);
        $this->assertStringContainsString('error de dedo al registrar', $log->description);
    }

    /**
     * Anular un gasto en efectivo:
     * - La gaveta sube por el monto
     * - El movimiento queda con deleted_at, voided_by y void_reason
     * - El listado lo incluye (tachado / con deleted_at)
     * - Auditoría propia
     */
    public function test_void_cash_expense_restores_drawer_cash_and_marks_voided(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id'     => $this->cashier->id,
            'opening_amount' => 200.00,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        // Gasto en efectivo de 20.00 -> gaveta pasa a 180.00
        $regResponse = $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$session->id}/movements", [
            'type'           => 'expense',
            'payment_method' => 'cash',
            'amount'         => 20.00,
            'description'    => 'Bolsas plásticas',
        ]);
        $movementId = $regResponse->json('movement.id');
        $this->assertEquals(180.00, $regResponse->json('stats.expected_cash'));

        // Anular el movimiento
        $voidResponse = $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$session->id}/movements/{$movementId}/void", [
            'reason' => 'Gasto duplicado cargado por equivocación',
        ]);

        $voidResponse->assertStatus(200)
            ->assertJsonPath('message', 'Movimiento de caja anulado correctamente.')
            ->assertJsonPath('movement.void_reason', 'Gasto duplicado cargado por equivocación')
            ->assertJsonPath('movement.voided_by.id', $this->cashier->id);

        // La gaveta recupera los 20.00 y vuelve a 200.00
        $this->assertEquals(200.00, $voidResponse->json('stats.expected_cash'));
        $this->assertEquals(0.00, $voidResponse->json('stats.cash_expenses'));

        // El registro está con soft delete en base de datos
        $movement = CashMovement::withTrashed()->findOrFail($movementId);
        $this->assertTrue($movement->trashed());
        $this->assertEquals($this->cashier->id, $movement->voided_by);
        $this->assertEquals('Gasto duplicado cargado por equivocación', $movement->void_reason);

        // El listado de movements de la sesión lo sigue trayendo para mostrarlo tachado
        $listResponse = $this->actingAs($this->cashier)->getJson("/api/cash-sessions/{$session->id}/movements");
        $listResponse->assertStatus(200);
        $this->assertCount(1, $listResponse->json());
        $this->assertNotNull($listResponse->json('0.deleted_at'));
        $this->assertEquals('Gasto duplicado cargado por equivocación', $listResponse->json('0.void_reason'));

        // Finanzas (/api/finance/cash-movements) no lo incluye porque usa el query normal sin trashed
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $now = now();
        $finResponse = $this->actingAs($superAdmin)->getJson("/api/finance/cash-movements?year={$now->year}&month={$now->month}");
        $finResponse->assertStatus(200);
        $this->assertEquals(0.00, $finResponse->json('total'));
        $this->assertEquals(0, $finResponse->json('count'));

        // Auditoría registrada
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->cashier->id,
            'action'  => 'cash_movement_voided',
            'module'  => 'CashMovement',
        ]);
    }

    /**
     * Permisos:
     * - El dueño de la caja puede corregir y anular
     * - Otro cajero no (403)
     * - Un administrador con register_others sí
     */
    public function test_permissions_for_correction_and_voiding(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id'     => $this->cashier->id,
            'opening_amount' => 100.00,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        $movement = CashMovement::create([
            'cash_register_session_id' => $session->id,
            'user_id'                  => $this->cashier->id,
            'type'                     => 'expense',
            'payment_method'           => 'cash',
            'amount'                   => 15.00,
            'description'              => 'Cinta de embalaje',
            'occurred_at'              => now(),
        ]);

        // Otro cajero intenta corregir -> 403
        $this->actingAs($this->otherCashier)->putJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}", [
            'reason'         => 'Intento no autorizado de cambiar monto',
            'payment_method' => 'qr',
        ])->assertStatus(403);

        // Otro cajero intenta anular -> 403
        $this->actingAs($this->otherCashier)->postJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}/void", [
            'reason' => 'Intento no autorizado de anular movimiento',
        ])->assertStatus(403);

        // El admin con register_others puede corregir
        $this->actingAs($this->admin)->putJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}", [
            'reason'         => 'Admin corrige pago que fue por QR',
            'payment_method' => 'qr',
        ])->assertStatus(200);

        // El admin con register_others puede anular
        $this->actingAs($this->admin)->postJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}/void", [
            'reason' => 'Admin anula gasto que fue reembolsado',
        ])->assertStatus(200);
    }

    /**
     * Caja cerrada:
     * - Rechaza con 422 («Esta caja está cerrada. Reábrela para corregirla.»)
     * - Después de reabrirla, sí se puede corregir y anular
     */
    public function test_cannot_correct_or_void_in_closed_session_without_reopening(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id'     => $this->cashier->id,
            'opening_amount' => 100.00,
            'status'         => 'closed',
            'opened_at'      => now()->subHours(5),
            'closed_at'      => now()->subHour(),
        ]);

        $movement = CashMovement::create([
            'cash_register_session_id' => $session->id,
            'user_id'                  => $this->cashier->id,
            'type'                     => 'expense',
            'payment_method'           => 'cash',
            'amount'                   => 25.00,
            'description'              => 'Gasto previo al cierre',
            'occurred_at'              => now()->subHours(2),
        ]);

        // Intento de corregir en caja cerrada -> 422
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}", [
            'reason'         => 'Intento de corregir gasto con caja cerrada',
            'payment_method' => 'qr',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Esta caja está cerrada. Reábrela para corregirla.');

        // Intento de anular en caja cerrada -> 422
        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}/void", [
            'reason' => 'Intento de anular gasto con caja cerrada',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Esta caja está cerrada. Reábrela para corregirla.');

        // Reabrir la caja (mecanismo oficial)
        $session->update(['status' => 'open']);

        // Ahora sí se puede corregir
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}", [
            'reason'         => 'Caja reabierta para corregir método de pago',
            'payment_method' => 'qr',
        ])->assertStatus(200);
    }

    /**
     * Validaciones:
     * - Sin motivo o con motivo corto (< 10 caracteres) -> 422
     * - Sin cambios -> 422 («No hay cambios para guardar.»)
     * - Fecha fuera del día de la caja o futura -> 422
     * - Movimiento de otra sesión -> 404
     * - Movimiento ya anulado -> 422
     */
    public function test_validations_reason_length_no_changes_and_invalid_date(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id'     => $this->cashier->id,
            'opening_amount' => 100.00,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        $otherSession = CashRegisterSession::create([
            'cashier_id'     => $this->admin->id,
            'opening_amount' => 50.00,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        $movement = CashMovement::create([
            'cash_register_session_id' => $session->id,
            'user_id'                  => $this->cashier->id,
            'type'                     => 'expense',
            'payment_method'           => 'cash',
            'amount'                   => 30.00,
            'description'              => 'Compra de cucharas',
            'occurred_at'              => now(),
        ]);

        // Sin motivo
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}", [
            'payment_method' => 'qr',
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        // Motivo demasiado corto (< 10 caracteres)
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}", [
            'reason'         => 'error',
            'payment_method' => 'qr',
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        // Sin cambios
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}", [
            'reason'         => 'Motivo válido de más de 10 letras pero sin cambios',
            'payment_method' => 'cash',
            'amount'         => 30.00,
            'description'    => 'Compra de cucharas',
        ])->assertStatus(422)->assertJsonPath('message', 'No hay cambios para guardar.');

        // Fecha futura
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}", [
            'reason'      => 'Cambio con fecha inválida futura',
            'occurred_at' => now()->addDays(2)->toIso8601String(),
        ])->assertStatus(422);

        // Movimiento de otra sesión
        $this->actingAs($this->admin)->putJson("/api/cash-sessions/{$otherSession->id}/movements/{$movement->id}", [
            'reason'         => 'Intento en sesión cruzada diferente',
            'payment_method' => 'qr',
        ])->assertStatus(404);

        // Movimiento ya anulado
        $movement->delete();
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$session->id}/movements/{$movement->id}", [
            'reason'         => 'Intento de modificar un movimiento anulado',
            'payment_method' => 'qr',
        ])->assertStatus(422)->assertJsonPath('message', 'El movimiento ya está anulado.');
    }

    /**
     * Reporte:
     * - El reporte de caja trae los movimientos anulados y los corregidos con sus campos
     * - Incluye movement_corrections_count
     */
    public function test_report_payload_includes_voided_and_edited_movements_and_count(): void
    {
        $session = CashRegisterSession::create([
            'cashier_id'     => $this->cashier->id,
            'opening_amount' => 150.00,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        // Movimiento 1: corregido
        $mov1 = CashMovement::create([
            'cash_register_session_id' => $session->id,
            'user_id'                  => $this->cashier->id,
            'type'                     => 'expense',
            'payment_method'           => 'qr',
            'amount'                   => 50.00,
            'description'              => 'Hielo',
            'occurred_at'              => now(),
        ]);
        $this->actingAs($this->cashier)->putJson("/api/cash-sessions/{$session->id}/movements/{$mov1->id}", [
            'reason'         => 'Se pagó en efectivo, no por QR',
            'payment_method' => 'cash',
        ])->assertStatus(200);

        // Movimiento 2: anulado
        $mov2 = CashMovement::create([
            'cash_register_session_id' => $session->id,
            'user_id'                  => $this->cashier->id,
            'type'                     => 'expense',
            'payment_method'           => 'cash',
            'amount'                   => 20.00,
            'description'              => 'Gasto cancelado',
            'occurred_at'              => now(),
        ]);
        $this->actingAs($this->cashier)->postJson("/api/cash-sessions/{$session->id}/movements/{$mov2->id}/void", [
            'reason' => 'Se canceló la compra y devolvieron el dinero',
        ])->assertStatus(200);

        // Consultar el reporte
        $reportResponse = $this->actingAs($this->cashier)->getJson("/api/cash-sessions/{$session->id}/report");
        $reportResponse->assertStatus(200);

        $this->assertEquals(2, $reportResponse->json('movement_corrections_count'));
        $movements = $reportResponse->json('movements');
        $this->assertCount(2, $movements);

        $m1 = collect($movements)->firstWhere('id', $mov1->id);
        $this->assertTrue($m1['is_edited']);
        $this->assertFalse($m1['is_voided']);
        $this->assertEquals('qr', $m1['edit_previous']['payment_method']);
        $this->assertEquals('Se pagó en efectivo, no por QR', $m1['edit_reason']);

        $m2 = collect($movements)->firstWhere('id', $mov2->id);
        $this->assertTrue($m2['is_voided']);
        $this->assertEquals('Se canceló la compra y devolvieron el dinero', $m2['void_reason']);
    }
}
