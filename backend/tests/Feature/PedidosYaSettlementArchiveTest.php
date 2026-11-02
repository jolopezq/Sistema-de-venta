<?php

namespace Tests\Feature;

use App\Models\PedidosYaSettlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PedidosYaSettlementArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $cashier;
    private string $fixtureFolder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->fixtureFolder = base_path('../docs/payoutArchive_PY_BO_20261001_to_20261101');
        if (!is_dir($this->fixtureFolder)) {
            $this->fixtureFolder = '/Users/jolopez/Sistema de Ventas/docs/payoutArchive_PY_BO_20261001_to_20261101';
        }
    }

    public function test_read_week_endpoint_parses_statement_pdf_and_checks_status_new(): void
    {
        if (!is_dir($this->fixtureFolder)) {
            $this->markTestSkipped('Carpeta de payoutArchive real no encontrada.');
        }

        $pdfPath = $this->fixtureFolder . '/account_statement_7800702619_1.pdf';
        $file = new UploadedFile($pdfPath, 'account_statement_7800702619_1.pdf', 'application/pdf', null, true);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/finance/pedidosya-settlements/read-week', [
            'statement_file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'statement_number' => '7800702619',
            'start_date'       => '2026-09-21',
            'end_date'         => '2026-09-27',
            'payout_date'      => '2026-10-01',
            'status'           => 'new',
            'statement'        => [
                'net_sales'    => 838.00,
                'service_fees' => 209.50,
                'advertising'  => 105.85,
                'taxes'        => 47.11,
                'total_payout' => 475.54,
            ],
        ]);
    }

    public function test_read_week_endpoint_detects_already_imported(): void
    {
        if (!is_dir($this->fixtureFolder)) {
            $this->markTestSkipped('Carpeta de payoutArchive real no encontrada.');
        }

        PedidosYaSettlement::create([
            'statement_number'  => '7800702619',
            'start_date'        => '2026-09-21',
            'end_date'          => '2026-09-27',
            'payout_date'       => '2026-10-01',
            'net_sales'         => 838.00,
            'service_fees'      => 209.50,
            'total_payout'      => 475.54,
            'calculated_payout' => 475.54,
            'user_id'           => $this->superAdmin->id,
        ]);

        $pdfPath = $this->fixtureFolder . '/account_statement_7800702619_1.pdf';
        $file = new UploadedFile($pdfPath, 'account_statement_7800702619_1.pdf', 'application/pdf', null, true);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/finance/pedidosya-settlements/read-week', [
            'statement_file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'statement_number' => '7800702619',
            'status'           => 'already_imported',
        ]);
    }

    public function test_read_week_endpoint_detects_same_dates_same_amounts(): void
    {
        if (!is_dir($this->fixtureFolder)) {
            $this->markTestSkipped('Carpeta de payoutArchive real no encontrada.');
        }

        // Cargada a mano sin statement_number
        PedidosYaSettlement::create([
            'start_date'        => '2026-09-21',
            'end_date'          => '2026-09-27',
            'payout_date'       => '2026-10-01',
            'net_sales'         => 838.00,
            'service_fees'      => 209.50,
            'total_payout'      => 475.54,
            'calculated_payout' => 475.54,
            'user_id'           => $this->superAdmin->id,
        ]);

        $pdfPath = $this->fixtureFolder . '/account_statement_7800702619_1.pdf';
        $file = new UploadedFile($pdfPath, 'account_statement_7800702619_1.pdf', 'application/pdf', null, true);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/finance/pedidosya-settlements/read-week', [
            'statement_file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'same_dates_same_amounts',
        ]);
    }

    public function test_import_week_from_archive_files_saves_settlement(): void
    {
        if (!is_dir($this->fixtureFolder)) {
            $this->markTestSkipped('Carpeta de payoutArchive real no encontrada.');
        }

        Storage::fake('local');

        $stPdf = new UploadedFile($this->fixtureFolder . '/account_statement_7800712391_1.pdf', 'account_statement_7800712391_1.pdf', 'application/pdf', null, true);
        $xls = new UploadedFile($this->fixtureFolder . '/invoice-7800712391.xls', 'invoice-7800712391.xls', 'application/vnd.ms-excel', null, true);
        $invPdf = new UploadedFile($this->fixtureFolder . '/invoice_7800712391.pdf', 'invoice_7800712391.pdf', 'application/pdf', null, true);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/finance/pedidosya-settlements', [
            'mode'           => 'folder',
            'statement_file' => $stPdf,
            'orders_xls'     => $xls,
            'invoice_pdf'    => $invPdf,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('settlement.statement_number', '7800712391');
        $response->assertJsonPath('settlement.invoice_number', '695176');
        $response->assertJsonPath('settlement.invoice_total', '347.36');
        $response->assertJsonPath('settlement.orders_count', 13);

        $settlement = PedidosYaSettlement::where('statement_number', '7800712391')->firstOrFail();
        $this->assertEquals(1, count($settlement->claims));
        $this->assertEquals('Orden incorrecta', $settlement->claims[0]['reason']);
        $this->assertNotNull($settlement->files['statement'] ?? null);
        $this->assertNotNull($settlement->files['orders'] ?? null);
        $this->assertNotNull($settlement->files['invoice'] ?? null);
    }

    public function test_download_settlement_file(): void
    {
        Storage::fake('local');
        $path = 'pedidosya/7800702619/statement.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 mock content');

        $settlement = PedidosYaSettlement::create([
            'statement_number'  => '7800702619',
            'start_date'        => '2026-09-21',
            'end_date'          => '2026-09-27',
            'payout_date'       => '2026-10-01',
            'net_sales'         => 838.00,
            'service_fees'      => 209.50,
            'total_payout'      => 475.54,
            'calculated_payout' => 475.54,
            'files'             => ['statement' => $path],
            'user_id'           => $this->superAdmin->id,
        ]);

        // Cajero no tiene permiso de Finanzas
        $this->actingAs($this->cashier)
            ->getJson("/api/finance/pedidosya-settlements/{$settlement->id}/files/statement")
            ->assertStatus(403);

        // Super Admin descarga
        $res = $this->actingAs($this->superAdmin)
            ->get("/api/finance/pedidosya-settlements/{$settlement->id}/files/statement");
        $res->assertOk();
    }

    public function test_read_week_endpoint_detects_same_dates_diff_amounts(): void
    {
        if (!is_dir($this->fixtureFolder)) {
            $this->markTestSkipped('Carpeta de payoutArchive real no encontrada.');
        }

        PedidosYaSettlement::create([
            'start_date'        => '2026-09-21',
            'end_date'          => '2026-09-27',
            'payout_date'       => '2026-10-01',
            'net_sales'         => 900.00,
            'service_fees'      => 200.00,
            'total_payout'      => 600.00,
            'calculated_payout' => 600.00,
            'user_id'           => $this->superAdmin->id,
        ]);

        $pdfPath = $this->fixtureFolder . '/account_statement_7800702619_1.pdf';
        $file = new UploadedFile($pdfPath, 'account_statement_7800702619_1.pdf', 'application/pdf', null, true);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/finance/pedidosya-settlements/read-week', [
            'statement_file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'same_dates_diff_amounts',
        ]);
        $this->assertNotEmpty($response->json('warnings'));
    }

    public function test_read_week_endpoint_detects_overlaps(): void
    {
        if (!is_dir($this->fixtureFolder)) {
            $this->markTestSkipped('Carpeta de payoutArchive real no encontrada.');
        }

        // Semana que se cruza parcialmente (del 20/09 al 25/09)
        PedidosYaSettlement::create([
            'start_date'        => '2026-09-20',
            'end_date'          => '2026-09-25',
            'payout_date'       => '2026-10-01',
            'net_sales'         => 500.00,
            'service_fees'      => 100.00,
            'total_payout'      => 400.00,
            'calculated_payout' => 400.00,
            'user_id'           => $this->superAdmin->id,
        ]);

        $pdfPath = $this->fixtureFolder . '/account_statement_7800702619_1.pdf';
        $file = new UploadedFile($pdfPath, 'account_statement_7800702619_1.pdf', 'application/pdf', null, true);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/finance/pedidosya-settlements/read-week', [
            'statement_file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'overlaps',
        ]);
        $this->assertNotEmpty($response->json('blockers'));
    }

    public function test_import_week_already_imported_returns_skipped(): void
    {
        if (!is_dir($this->fixtureFolder)) {
            $this->markTestSkipped('Carpeta de payoutArchive real no encontrada.');
        }

        Storage::fake('local');

        $stPdf = new UploadedFile($this->fixtureFolder . '/account_statement_7800702619_1.pdf', 'account_statement_7800702619_1.pdf', 'application/pdf', null, true);

        $res1 = $this->actingAs($this->superAdmin)->postJson('/api/finance/pedidosya-settlements', [
            'mode'           => 'folder',
            'statement_file' => $stPdf,
        ]);
        $res1->assertStatus(201);

        // Segunda vez: ya importada
        $res2 = $this->actingAs($this->superAdmin)->postJson('/api/finance/pedidosya-settlements', [
            'mode'           => 'folder',
            'statement_file' => $stPdf,
        ]);
        $res2->assertStatus(201);
        $this->assertEquals('7800702619', $res2->json('settlement.statement_number'));
        $this->assertStringContainsString('ya había sido importada', implode(' ', $res2->json('warnings')));
    }

    public function test_import_week_same_dates_same_amounts_updates_without_duplicate(): void
    {
        if (!is_dir($this->fixtureFolder)) {
            $this->markTestSkipped('Carpeta de payoutArchive real no encontrada.');
        }

        Storage::fake('local');

        // Creada previamente a mano
        $existing = PedidosYaSettlement::create([
            'start_date'        => '2026-09-21',
            'end_date'          => '2026-09-27',
            'payout_date'       => '2026-10-01',
            'net_sales'         => 838.00,
            'service_fees'      => 209.50,
            'advertising'       => 105.85,
            'taxes'             => 47.11,
            'total_payout'      => 475.54,
            'calculated_payout' => 475.54,
            'user_id'           => $this->superAdmin->id,
        ]);

        $stPdf = new UploadedFile($this->fixtureFolder . '/account_statement_7800702619_1.pdf', 'account_statement_7800702619_1.pdf', 'application/pdf', null, true);
        $invPdf = new UploadedFile($this->fixtureFolder . '/invoice_7800702619.pdf', 'invoice_7800702619.pdf', 'application/pdf', null, true);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/finance/pedidosya-settlements', [
            'mode'           => 'folder',
            'statement_file' => $stPdf,
            'invoice_pdf'    => $invPdf,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(1, PedidosYaSettlement::count());
        $fresh = $existing->fresh();
        $this->assertEquals('7800702619', $fresh->statement_number);
        $this->assertEquals('688161', $fresh->invoice_number);
        $this->assertNotNull($fresh->files['statement'] ?? null);
        $this->assertNotNull($fresh->files['invoice'] ?? null);
    }

    public function test_invoices_summary_endpoint(): void
    {
        PedidosYaSettlement::create([
            'statement_number'      => '7800702619',
            'start_date'            => '2026-09-21',
            'end_date'              => '2026-09-27',
            'payout_date'           => '2026-10-01',
            'net_sales'             => 838.00,
            'service_fees'          => 209.50,
            'total_payout'          => 475.54,
            'calculated_payout'     => 475.54,
            'invoice_number'        => '688161',
            'invoice_date'          => '2026-09-28',
            'invoice_authorization' => 'AUTH123',
            'invoice_total'         => 362.46,
            'user_id'               => $this->superAdmin->id,
        ]);

        PedidosYaSettlement::create([
            'statement_number'      => '7800706345',
            'start_date'            => '2026-09-28',
            'end_date'              => '2026-10-04',
            'payout_date'           => '2026-10-08',
            'net_sales'             => 824.00,
            'service_fees'          => 206.00,
            'total_payout'          => 499.74,
            'calculated_payout'     => 499.74,
            'invoice_number'        => '691234',
            'invoice_date'          => '2026-10-05',
            'invoice_authorization' => 'AUTH456',
            'invoice_total'         => 324.26,
            'user_id'               => $this->superAdmin->id,
        ]);

        // Filtrar por octubre 2026
        $res = $this->actingAs($this->superAdmin)->getJson('/api/finance/pedidosya-settlements/invoices-summary?year=2026&month=10');
        $res->assertOk();
        $res->assertJsonPath('summary.count', 1);
        $res->assertJsonPath('summary.total_amount', 324.26);
        $this->assertEquals(round(324.26 * 0.13, 2), $res->json('summary.total_tax_credit'));
    }
}
