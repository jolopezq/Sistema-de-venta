<?php

namespace Tests\Feature;

use App\Services\PedidosYa\StatementReader;
use App\Services\PedidosYa\InvoiceReader;
use App\Services\PedidosYa\OrderListReader;
use InvalidArgumentException;
use Tests\TestCase;

class PedidosYaReadersTest extends TestCase
{
    private StatementReader $statementReader;
    private InvoiceReader $invoiceReader;
    private OrderListReader $orderListReader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statementReader = app(StatementReader::class);
        $this->invoiceReader = app(InvoiceReader::class);
        $this->orderListReader = app(OrderListReader::class);
    }

    // -------------------------------------------------------------------------
    // StatementReader (interpretación sobre texto anonimizado)
    // -------------------------------------------------------------------------

    public function test_statement_reader_parses_normal_week(): void
    {
        $text = <<<TEXT
NOMBRE COMERCIO S.R.L.
Moneda: BOB Estado de cuenta semanal
Período del 21/09/2026 al 27/09/2026
Resumen
Ventas netas\tBs 838,00
Envíos a cargo del local\tBs 0,00
Servicios PedidosYa\tBs -209,50
Cargos operativos\tBs 0,00
Publicidad dentro de la aplicación\tBs -105,85
Reintegros\tBs 0,00
Ajustes de liquidación\tBs 0,00
Impuestos\tBs -47,11
Ventas con pago fuera de la App cobradas\tBs 0,00
Subtotal\tBs. 475.54
Total Crédito PedidosYa\tBs. 0.00
Total\tBs. 475.54
Total liquidado del 21/09/2026 al 27/09/2026
Bs 475,54
El día 01/10/2026 procesaremos el pago, lo recibirás en la cuenta BANCO EJEMPLO
TEXT;

        $res = $this->statementReader->interpret($text, '7800702619');

        $this->assertEquals('7800702619', $res['statement_number']);
        $this->assertEquals('2026-09-21', $res['start_date']);
        $this->assertEquals('2026-09-27', $res['end_date']);
        $this->assertEquals('2026-10-01', $res['payout_date']);
        $this->assertEquals(838.00, $res['net_sales']);
        $this->assertEquals(0.00, $res['delivery_fees']);
        $this->assertEquals(209.50, $res['service_fees']);
        $this->assertEquals(0.00, $res['operating_charges']);
        $this->assertEquals(105.85, $res['advertising']);
        $this->assertEquals(0.00, $res['refunds']);
        $this->assertEquals(47.11, $res['taxes']);
        $this->assertEquals(475.54, $res['total_payout']);
    }

    public function test_statement_reader_parses_week_with_claims_and_thousands(): void
    {
        // Semana con reclamos (Cargos operativos: Bs 40,00)
        $text = <<<TEXT
COMERCIO EJEMPLO
Estado de cuenta semanal
Período del 05/10/2026 al 11/10/2026
Resumen
Ventas netas\tBs 686,00
Envíos a cargo del local\tBs 0,00
Servicios PedidosYa\tBs -171,50
Cargos operativos\tBs -40,00
Publicidad dentro de la aplicación\tBs -90,71
Reintegros\tBs 0,00
Ajustes de liquidación\tBs 0,00
Impuestos\tBs -45,15
Ventas con pago fuera de la App cobradas\tBs 0,00
Subtotal\tBs. 338.64
Total Crédito PedidosYa\tBs. 0.00
Total\tBs. 338.64
Total liquidado del 05/10/2026 al 11/10/2026
Bs 338,64
El día 15/10/2026 procesaremos el pago
TEXT;

        $res = $this->statementReader->interpret($text, '7800712391');

        $this->assertEquals('2026-10-05', $res['start_date']);
        $this->assertEquals('2026-10-11', $res['end_date']);
        $this->assertEquals('2026-10-15', $res['payout_date']);
        $this->assertEquals(686.00, $res['net_sales']);
        $this->assertEquals(171.50, $res['service_fees']);
        $this->assertEquals(40.00, $res['operating_charges']);
        $this->assertEquals(90.71, $res['advertising']);
        $this->assertEquals(45.15, $res['taxes']);
        $this->assertEquals(338.64, $res['total_payout']);
    }

    public function test_statement_reader_parses_week_with_refunds_and_thousands_point(): void
    {
        // Semana con reintegro (Bs 38,26) y ventas con punto de miles (Bs 1.166,00)
        $text = <<<TEXT
COMERCIO EJEMPLO
Estado de cuenta semanal
Período del 12/10/2026 al 18/10/2026
Resumen
Ventas netas\tBs 1.166,00
Envíos a cargo del local\tBs 0,00
Servicios PedidosYa\tBs -302,50
Cargos operativos\tBs 0,00
Publicidad dentro de la aplicación\tBs -100,70
Reintegros\tBs 38,26
Ajustes de liquidación\tBs 0,00
Impuestos\tBs -54,52
Ventas con pago fuera de la App cobradas\tBs 0,00
Subtotal\tBs. 746.54
Total Crédito PedidosYa\tBs. 0.00
Total\tBs. 746.54
Total liquidado del 12/10/2026 al 18/10/2026
Bs 746,54
El día 22/10/2026 procesaremos el pago
TEXT;

        $res = $this->statementReader->interpret($text, '7800713840');

        $this->assertEquals(1166.00, $res['net_sales']);
        $this->assertEquals(302.50, $res['service_fees']);
        $this->assertEquals(100.70, $res['advertising']);
        $this->assertEquals(38.26, $res['refunds']);
        $this->assertEquals(54.52, $res['taxes']);
        $this->assertEquals(746.54, $res['total_payout']);
    }

    public function test_statement_reader_rejects_unbalanced_statement(): void
    {
        // Montos alterados a propósito donde el total no cuadra
        $text = <<<TEXT
Estado de cuenta semanal
Período del 21/09/2026 al 27/09/2026
Resumen
Ventas netas\tBs 838,00
Envíos a cargo del local\tBs 0,00
Servicios PedidosYa\tBs -209,50
Cargos operativos\tBs 0,00
Publicidad dentro de la aplicación\tBs -105,85
Reintegros\tBs 0,00
Ajustes de liquidación\tBs 0,00
Impuestos\tBs -47,11
Ventas con pago fuera de la App cobradas\tBs 0,00
Total liquidado del 21/09/2026 al 27/09/2026
Bs 999,99
El día 01/10/2026 procesaremos el pago
TEXT;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no cuadra');

        $this->statementReader->interpret($text, '7800702619');
    }

    public function test_statement_reader_rejects_unhandled_lines_with_non_zero(): void
    {
        // Ajustes de liquidación con monto distinto de 0
        $text = <<<TEXT
Estado de cuenta semanal
Período del 21/09/2026 al 27/09/2026
Resumen
Ventas netas\tBs 838,00
Envíos a cargo del local\tBs 0,00
Servicios PedidosYa\tBs -209,50
Cargos operativos\tBs 0,00
Publicidad dentro de la aplicación\tBs -105,85
Reintegros\tBs 0,00
Ajustes de liquidación\tBs 50,00
Impuestos\tBs -47,11
Ventas con pago fuera de la App cobradas\tBs 0,00
Total liquidado del 21/09/2026 al 27/09/2026
Bs 525,54
El día 01/10/2026 procesaremos el pago
TEXT;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conceptos no estándar');

        $this->statementReader->interpret($text, '7800702619');
    }

    // -------------------------------------------------------------------------
    // InvoiceReader (interpretación sobre texto anonimizado)
    // -------------------------------------------------------------------------

    public function test_invoice_reader_parses_invoice_text(): void
    {
        $text = <<<TEXT
PEDIDOSYA SERVICIOS S.A.
SUCURSAL N. 1
NIT\t358245025
FACTURA Nº 688161
COD. AUTORIZACIÓN
18831A4071F6BC0108BFE
F212FE6FC79A8376EDAA3
7A11624BE43BF74
FACTURA
(Con Derecho A Crédito Fiscal)
Fecha:\t28/09/2026 4:52 PM\tNIT/CI/CEX: 0000000000
TOTAL Bs 362.46
TOTAL A PAGAR Bs 362.46
IMPORTE BASE CRÉDITO FISCAL 362.46
TEXT;

        $res = $this->invoiceReader->interpret($text);

        $this->assertEquals('688161', $res['invoice_number']);
        $this->assertEquals('2026-09-28', $res['invoice_date']);
        $this->assertEquals('18831A4071F6BC0108BFEF212FE6FC79A8376EDAA37A11624BE43BF74', $res['invoice_authorization']);
        $this->assertEquals(362.46, $res['invoice_total']);
        $this->assertEquals(362.46, $res['fiscal_credit_base']);
    }

    // -------------------------------------------------------------------------
    // Pruebas contra la carpeta real (si existe en la máquina)
    // -------------------------------------------------------------------------

    public function test_reads_real_archive_folder_if_present(): void
    {
        $folder = base_path('../docs/payoutArchive_PY_BO_20261001_to_20261101');
        if (!is_dir($folder)) {
            $folder = '/Users/jolopez/Sistema de Ventas/docs/payoutArchive_PY_BO_20261001_to_20261101';
        }
        if (!is_dir($folder)) {
            $this->markTestSkipped('Carpeta de payoutArchive real no encontrada.');
        }

        // 1. Probar un estado de cuenta real
        $stPdf = $folder . '/account_statement_7800712391_1.pdf';
        $stData = $this->statementReader->read($stPdf, basename($stPdf));
        $this->assertEquals('7800712391', $stData['statement_number']);
        $this->assertEquals('2026-10-05', $stData['start_date']);
        $this->assertEquals('2026-10-11', $stData['end_date']);
        $this->assertEquals(686.00, $stData['net_sales']);
        $this->assertEquals(40.00, $stData['operating_charges']);
        $this->assertEquals(338.64, $stData['total_payout']);

        // 2. Probar una factura real
        $invPdf = $folder . '/invoice_7800712391.pdf';
        $invData = $this->invoiceReader->read($invPdf);
        $this->assertEquals('695176', $invData['invoice_number']);
        $this->assertEquals('2026-10-12', $invData['invoice_date']);
        $this->assertEquals(347.36, $invData['invoice_total']);

        // 3. Probar un Excel real con pedidos y reclamo
        $xlsPath = $folder . '/invoice-7800712391.xls';
        $xlsData = $this->orderListReader->read($xlsPath);
        $this->assertEquals(13, $xlsData['orders_count']);
        $this->assertEquals(1, $xlsData['claims_count']);
        $this->assertEquals(40.00, $xlsData['total_claims']);
        $this->assertEquals('2318175213', $xlsData['claims'][0]['order_number']);
        $this->assertEquals('Orden incorrecta', $xlsData['claims'][0]['reason']);
    }
}
