<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PedidosYaOrder;
use App\Models\PedidosYaSettlement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\PedidosYa\InvoiceReader;
use App\Services\PedidosYa\StatementReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Importación por carpeta de PedidosYa sin depender de la carpeta real del dueño: el texto de los PDF es
 * anonimizado (los lectores reales lo interpretan) y el Excel se arma en la prueba.
 *
 * Semana de prueba: 19 al 25/10/2026, liquidación 7800000005, ventas netas 150 (tres pedidos de 50).
 */
class PedidosYaFolderImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private string $dir;

    public const NUMBER = '7800000005';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->dir = sys_get_temp_dir() . '/pya-' . uniqid();
        @mkdir($this->dir);

        // Lectores reales sobre texto anonimizado (el PDF no se puede armar en la prueba)
        $this->app->instance(StatementReader::class, new class extends StatementReader {
            public function read(string|UploadedFile $file, ?string $filename = null): array
            {
                return $this->interpret(<<<TEXT
COMERCIO EJEMPLO
Estado de cuenta semanal
Período del 19/10/2026 al 25/10/2026
Resumen
Ventas netas\tBs 150,00
Envíos a cargo del local\tBs 0,00
Servicios PedidosYa\tBs -37,50
Cargos operativos\tBs 0,00
Publicidad dentro de la aplicación\tBs -10,00
Reintegros\tBs 0,00
Ajustes de liquidación\tBs 0,00
Impuestos\tBs -7,10
Ventas con pago fuera de la App cobradas\tBs 0,00
Subtotal\tBs. 95.40
Total Crédito PedidosYa\tBs. 0.00
Total\tBs. 95.40
Total liquidado del 19/10/2026 al 25/10/2026
Bs 95,40
El día 29/10/2026 procesaremos el pago, lo recibirás en la cuenta BANCO EJEMPLO
TEXT, PedidosYaFolderImportTest::NUMBER);
            }
        });
        $this->app->instance(InvoiceReader::class, new class extends InvoiceReader {
            public function read(string|UploadedFile $file): array
            {
                return $this->interpret("PEDIDOSYA SERVICIOS S.A.\nFACTURA Nº 701999\nFACTURA\nFecha:\t26/10/2026 2:08 PM\nTOTAL A PAGAR Bs 54.60\nIMPORTE BASE CRÉDITO FISCAL 54.60");
            }
        });

        $category = Category::create(['name' => 'Bowls']);
        Product::create(['name' => 'Classic Bowl', 'price' => 50, 'is_active' => true, 'category_id' => $category->id]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function upload(string $name, string $content = 'x'): UploadedFile
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        return new UploadedFile($path, $name, null, null, true);
    }

    /** Excel OOXML con la hoja «Lista de Pedidos» (una fila por pedido de Bs 50, comisión 12,50). */
    private function xls(array $orders): UploadedFile
    {
        $path = $this->dir . '/invoice-' . self::NUMBER . '.xls';
        $headers = ['Número de pedido', 'Order Status', 'Sucursal', 'Fecha de pedido', 'Monto bruto de la venta', 'Monto neto de la Venta ($)', 'Monto comisionable Servicios PedidosYa', 'Servicio Ventas PedidosYA (%)', 'Servicio Ventas PedidoYa ($)', 'Método de pago', 'Cobrado por', 'Método de entrega'];
        $rows = [$headers];
        foreach ($orders as [$number, $date]) {
            $rows[] = [$number, '', 'Local', $date, '50', '50', '50', '25.00%', '12.5', 'Pago en la aplicación', 'PedidosYa', 'Envío de PedidosYa'];
        }
        $cell = fn ($v, $ref) => is_numeric($v) && !preg_match('/^\d{8,}$/', (string) $v)
            ? "<c r=\"{$ref}\"><v>{$v}</v></c>"
            : "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>" . htmlspecialchars((string) $v) . '</t></is></c>';
        $xmlRows = '';
        foreach ($rows as $i => $row) {
            $cells = '';
            foreach ($row as $j => $v) {
                if ($v === '') {
                    continue; // celda vacía omitida, como en un Excel real
                }
                $cells .= $cell($v, chr(65 + $j) . ($i + 1));
            }
            $xmlRows .= '<row r="' . ($i + 1) . '">' . $cells . '</row>';
        }
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Lista de Pedidos" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xmlRows . '</sheetData></worksheet>');
        $zip->close();

        return new UploadedFile($path, basename($path), null, null, true);
    }

    /** CSV del portal (separado por «;», con BOM, como lo deja Excel en español). */
    private function csv(array $orders): UploadedFile
    {
        $lines = ["\xEF\xBB\xBFNro de pedido;Estado del pedido;Fecha del pedido;Forma de pago;Tipo de entrega;Total parcial;Comisión;Impuesto;Descuento financiado por usted;Descuento financiado por PedidosYa;Ingreso estimado;Artículos del pedido"];
        foreach ($orders as [$number, $at, $items]) {
            $lines[] = "{$number};Entregado;{$at};Pago online;Delivery de PedidosYa;50,00;12,50;0,00;0,00;0,00;37,50;{$items}";
        }
        return $this->upload('orderDetails.csv', implode("\n", $lines) . "\n");
    }

    private const ORDERS = [['1000000001', '20/10/2026'], ['1000000002', '22/10/2026'], ['1000000003', '24/10/2026']];

    private function files(array $extra = []): array
    {
        return array_merge([
            'statement_file' => $this->upload('account_statement_' . self::NUMBER . '_1.pdf'),
            'orders_xls'     => $this->xls(self::ORDERS),
            'invoice_pdf'    => $this->upload('invoice_' . self::NUMBER . '.pdf'),
        ], $extra);
    }

    private function import(array $extra = [], string $salesMode = 'create')
    {
        return $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements', array_merge(
            ['mode' => 'folder', 'sales_mode' => $salesMode],
            $this->files($extra)
        ), ['Accept' => 'application/json']);
    }

    private function fullCsv(): UploadedFile
    {
        return $this->csv([
            ['1000000001', '2026-10-20 13:05', '1 Classic Bowl'],
            ['1000000002', '2026-10-22 19:40', '1 Classic Bowl'],
            ['1000000003', '2026-10-24 12:10', '1 Classic Bowl'],
            ['1000000099', '2026-10-27 12:00', '1 Classic Bowl'],   // otra semana: no entra
        ]);
    }

    public function test_full_week_with_csv_loads_everything(): void
    {
        $res = $this->import(['csv_file' => $this->fullCsv()])->assertCreated();
        $res->assertJsonPath('skipped', false);

        $st = PedidosYaSettlement::sole();
        $this->assertSame(self::NUMBER, $st->statement_number);
        $this->assertSame('2026-10-29', $st->payout_date->toDateString());
        $this->assertEqualsWithDelta(95.40, (float) $st->total_payout, 0.001);
        $this->assertSame('701999', $st->invoice_number);
        $this->assertSame('csv', $st->orders_source);
        $this->assertSame(3, $st->orders_count);
        $this->assertSame(3, $st->delivered_count);
        $this->assertEqualsWithDelta(150.0, (float) $st->csv_gross_sales, 0.001);

        // Archivos guardados y descargables
        foreach (['statement', 'orders', 'invoice'] as $type) {
            Storage::disk('local')->assertExists($st->files[$type]);
            $this->actingAs($this->owner)->get("/api/finance/pedidosya-settlements/{$st->id}/files/{$type}")->assertOk();
        }

        // Pedidos con artículos y hora, y sus ventas creadas
        $this->assertSame(3, PedidosYaOrder::whereNotNull('items_text')->whereNotNull('sale_id')->count());
        $this->assertSame('2026-10-22 19:40', PedidosYaOrder::where('order_number', '1000000002')->first()->order_date->format('Y-m-d H:i'));
        $this->assertSame(3, Sale::where('source', 'pedidosya')->count());
        $this->assertSame(3, $res->json('sales.created'));
        $this->assertNull(PedidosYaOrder::where('order_number', '1000000099')->first());
    }

    public function test_without_csv_orders_come_from_the_excel_and_can_be_completed_later(): void
    {
        $this->import()->assertCreated();
        $st = PedidosYaSettlement::sole();
        $this->assertSame('xls', $st->orders_source);
        $this->assertSame(3, $st->orders_count);
        $this->assertSame(3, $st->delivered_count);
        $this->assertSame(3, PedidosYaOrder::whereNull('items_text')->count());
        $this->assertSame(0, Sale::count());

        // El informe no ofrece «crear ventas» que no se pueden crear: le falta el detalle
        $report = $this->actingAs($this->owner)->getJson("/api/finance/pedidosya-settlements/{$st->id}/report")->assertOk();
        $report->assertJsonPath('control.without_sale', 0)->assertJsonPath('control.without_detail', 3);

        // Más tarde, con el CSV: la vista previa ofrece completarla y la importación crea las ventas
        $preview = $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements/read-week', $this->files(['csv_file' => $this->fullCsv()]), ['Accept' => 'application/json']);
        $preview->assertOk()->assertJsonPath('status', 'complete')->assertJsonMissingPath('orders_plan');

        $res = $this->import(['csv_file' => $this->fullCsv()])->assertCreated();
        $res->assertJsonPath('completed', true)->assertJsonPath('message', 'Semana de PedidosYa completada.');
        $this->assertSame(1, PedidosYaSettlement::count());
        $st->refresh();
        $this->assertSame('csv', $st->orders_source);
        $this->assertSame(3, PedidosYaOrder::whereNotNull('items_text')->whereNotNull('sale_id')->count());
        $this->assertSame(3, Sale::count());

        // Y después ya no hay nada que completar: se salta
        $this->import(['csv_file' => $this->fullCsv()])->assertOk()->assertJsonPath('skipped', true);
        $this->assertSame(3, Sale::count());
    }

    public function test_weeks_saved_by_the_first_version_are_completed(): void
    {
        // Primera versión: semana «csv» sin ningún pedido (el CSV no cubría la semana)
        $empty = PedidosYaSettlement::create([
            'statement_number' => self::NUMBER, 'start_date' => '2026-10-19', 'end_date' => '2026-10-25', 'payout_date' => '2026-10-29',
            'net_sales' => 150, 'service_fees' => 37.5, 'advertising' => 10, 'taxes' => 7.1,
            'total_payout' => 95.4, 'calculated_payout' => 95.4, 'orders_source' => 'csv', 'user_id' => $this->owner->id,
        ]);
        $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements/read-week', $this->files(), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('status', 'complete');
        $this->import([], 'skip')->assertCreated()->assertJsonPath('completed', true);
        $this->assertSame(3, $empty->orders()->count());

        // Primera versión: pedidos del Excel con «Pedido PedidosYa #…» como artículos
        PedidosYaOrder::query()->update(['items_text' => DB::raw("'Pedido PedidosYa #' || order_number")]);
        $report = $this->actingAs($this->owner)->getJson("/api/finance/pedidosya-settlements/{$empty->id}/report")->assertOk();
        $report->assertJsonPath('control.without_sale', 0)->assertJsonPath('control.without_detail', 3);

        $res = $this->import(['csv_file' => $this->fullCsv()])->assertCreated();
        $res->assertJsonPath('completed', true)->assertJsonPath('sales.created', 3);
        $this->assertSame(0, PedidosYaOrder::where('items_text', 'like', 'Pedido PedidosYa #%')->count());
    }

    public function test_an_imported_week_without_sales_gets_them_when_imported_again(): void
    {
        // Importada con su CSV pero sin crear ventas («solo montos»)
        $this->import(['csv_file' => $this->fullCsv()], 'skip')->assertCreated();
        $this->assertSame(0, Sale::count());

        $preview = $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements/read-week', $this->files(), ['Accept' => 'application/json']);
        $preview->assertOk()->assertJsonPath('status', 'complete')->assertJsonPath('sales.summary.create', 3);
        $this->assertStringContainsString('3 pedido(s) no tienen su venta', implode(' ', $preview->json('warnings')));

        $this->import()->assertCreated()->assertJsonPath('sales.created', 3);
        $this->assertSame(3, Sale::count());
        $this->import()->assertOk()->assertJsonPath('skipped', true);
    }

    public function test_a_csv_that_cannot_be_read_falls_back_to_the_excel_with_a_visible_warning(): void
    {
        $bad = $this->upload('orderDetails.csv', "foo;bar\n1;2\n");
        $res = $this->import(['csv_file' => $bad])->assertCreated();

        $this->assertStringContainsString('No se pudo leer el CSV de pedidos', implode(' ', $res->json('warnings')));
        $st = PedidosYaSettlement::sole();
        $this->assertSame('xls', $st->orders_source);
        $this->assertSame(3, $st->orders_count);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_csv_missing_some_orders_keeps_them_from_the_excel(): void
    {
        $partial = $this->csv([
            ['1000000001', '2026-10-20 13:05', '1 Classic Bowl'],
            ['1000000002', '2026-10-22 19:40', '1 Classic Bowl'],
        ]);
        $res = $this->import(['csv_file' => $partial])->assertCreated();

        $this->assertStringContainsString('1 pedido(s) del Excel (del 24/10 al 24/10) no están en el CSV', implode(' ', $res->json('warnings')));
        $this->assertStringContainsString('pon también el CSV de ese mes', implode(' ', $res->json('warnings')));
        $this->assertStringContainsString('1 pedido(s) sin detalle de artículos', implode(' ', $res->json('warnings')));
        $st = PedidosYaSettlement::sole();
        $this->assertSame('mixed', $st->orders_source);
        $this->assertSame(3, $st->orders_count);
        $this->assertSame(2, Sale::count());
        $this->assertNull(PedidosYaOrder::where('order_number', '1000000003')->first()->items_text);
    }

    public function test_several_csv_files_are_joined_for_a_week_that_crosses_months(): void
    {
        // Un CSV por mes: la semana toma sus pedidos de los dos
        $first = $this->csv([['1000000001', '2026-10-20 13:05', '1 Classic Bowl'], ['1000000002', '2026-10-22 19:40', '1 Classic Bowl']]);
        $second = $this->upload('orderDetails-2.csv', "\xEF\xBB\xBFNro de pedido,Estado del pedido,Fecha del pedido,Forma de pago,Tipo de entrega,Total parcial,Comisión,Impuesto,Descuento financiado por usted,Descuento financiado por PedidosYa,Ingreso estimado,Artículos del pedido\n"
            . "1000000003,Entregado,2026-10-24 12:10,Pago online,Delivery de PedidosYa,\"50,00\",\"12,50\",\"0,00\",\"0,00\",\"0,00\",\"37,50\",1 Classic Bowl\n");

        $res = $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements', array_merge(
            ['mode' => 'folder', 'sales_mode' => 'create', 'csv_files' => [$first, $second]],
            $this->files()
        ), ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('csv', PedidosYaSettlement::sole()->orders_source);
        $this->assertSame(3, $res->json('sales.created'));
        $this->assertSame(3, Sale::count());
    }

    public function test_a_deleted_week_can_be_imported_again_without_duplicating_sales(): void
    {
        $this->import(['csv_file' => $this->fullCsv()])->assertCreated();
        $st = PedidosYaSettlement::sole();

        $this->actingAs($this->owner)->deleteJson("/api/finance/pedidosya-settlements/{$st->id}")->assertOk();
        $this->assertNull(PedidosYaSettlement::withTrashed()->find($st->id)->statement_number);

        $res = $this->import(['csv_file' => $this->fullCsv()])->assertCreated();
        $this->assertSame(self::NUMBER, PedidosYaSettlement::sole()->statement_number);
        // Las ventas creadas la primera vez se vinculan, no se duplican
        $this->assertSame(3, Sale::count());
        $this->assertSame(0, $res->json('sales.created'));
        $this->assertSame(3, $res->json('sales.already'));
    }

    public function test_a_number_still_held_by_an_old_deleted_week_is_released(): void
    {
        // Semana borrada antes de esta corrección: conserva el número
        $old = PedidosYaSettlement::create([
            'statement_number' => self::NUMBER, 'start_date' => '2026-10-19', 'end_date' => '2026-10-25',
            'net_sales' => 150, 'service_fees' => 37.5, 'total_payout' => 95.4, 'calculated_payout' => 95.4, 'user_id' => $this->owner->id,
        ]);
        $old->delete();

        $this->import()->assertCreated();
        $this->assertSame(self::NUMBER, PedidosYaSettlement::sole()->statement_number);
        $this->assertStringContainsString(self::NUMBER, PedidosYaSettlement::withTrashed()->find($old->id)->notes);
    }

    public function test_a_manual_week_with_the_same_amounts_gets_number_payout_date_and_files(): void
    {
        $manual = PedidosYaSettlement::create([
            'start_date' => '2026-10-19', 'end_date' => '2026-10-25', 'payout_date' => null,
            'net_sales' => 150, 'service_fees' => 37.5, 'advertising' => 10, 'taxes' => 7.1,
            'total_payout' => 95.4, 'calculated_payout' => 95.4, 'user_id' => $this->owner->id,
        ]);

        $res = $this->import()->assertCreated();
        $this->assertStringContainsString('cargada a mano', implode(' ', $res->json('warnings')));
        $manual->refresh();
        $this->assertSame(1, PedidosYaSettlement::count());
        $this->assertSame(self::NUMBER, $manual->statement_number);
        $this->assertSame('2026-10-29', $manual->payout_date->toDateString());
        $this->assertSame('701999', $manual->invoice_number);
        $this->assertCount(3, $manual->files);
        $this->assertSame(0, PedidosYaOrder::count());   // sus pedidos no se tocan
    }

    public function test_the_statement_number_must_be_digits(): void
    {
        $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements/read-week', array_merge(
            $this->files(), ['statement_number' => '../../public/x']
        ), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('statement_number');
    }

    public function test_missing_excel_and_invoice_are_warned(): void
    {
        $res = $this->actingAs($this->owner)->post('/api/finance/pedidosya-settlements/read-week', [
            'statement_file' => $this->upload('account_statement_' . self::NUMBER . '_1.pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        $warnings = implode(' ', $res->json('warnings'));
        $this->assertStringContainsString('Falta la factura fiscal', $warnings);
        $this->assertStringContainsString('Falta el Excel de pedidos', $warnings);
    }
}
