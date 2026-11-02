<?php

namespace App\Services\PedidosYa;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Smalot\PdfParser\Parser;

class StatementReader
{
    public function __construct(
        protected ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? new Parser();
    }

    /**
     * Lee un archivo PDF de estado de cuenta semanal de PedidosYa.
     */
    public function read(string|UploadedFile $file, ?string $filename = null): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $name = $filename ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file));

        if (!file_exists($path) || !is_readable($path)) {
            throw new InvalidArgumentException("El archivo PDF del estado de cuenta no existe o no se puede leer.");
        }

        $pdf = $this->parser->parseFile($path);
        $text = $pdf->getText();

        // Extraer número de liquidación del nombre del archivo si existe (ej. account_statement_7800702619_1.pdf)
        $statementNumber = null;
        if (preg_match('/account_statement_(\d+)/i', $name, $m)) {
            $statementNumber = $m[1];
        } elseif (preg_match('/\b(\d{7,12})\b/', $name, $m)) {
            $statementNumber = $m[1];
        }

        return $this->interpret($text, $statementNumber);
    }

    /**
     * Interpreta el texto extraído del PDF.
     */
    public function interpret(string $text, ?string $statementNumber = null): array
    {
        // 1. Extraer período: "Período del 21/09/2026 al 27/09/2026"
        if (!preg_match('/Per.*?(\d{2}\/\d{2}\/\d{4})\s+al\s+(\d{2}\/\d{2}\/\d{4})/ui', $text, $mPeriod)) {
            throw new InvalidArgumentException("No se encontró el período de fechas en el estado de cuenta.");
        }

        $startDate = Carbon::createFromFormat('d/m/Y', $mPeriod[1])->toDateString();
        $endDate = Carbon::createFromFormat('d/m/Y', $mPeriod[2])->toDateString();

        // 2. Extraer fecha de pago: "El día 01/10/2026 procesaremos el pago"
        $payoutDate = null;
        if (preg_match('/El d[íi]a\s+(\d{2}\/\d{2}\/\d{4})\s+procesaremos/ui', $text, $mPago)) {
            $payoutDate = Carbon::createFromFormat('d/m/Y', $mPago[1])->toDateString();
        }

        // Helper para parsear números con formato boliviano ("838,00" o "1.166,00")
        $parseNum = function (string $pattern, string $content, bool $required = false): float {
            if (preg_match($pattern, $content, $m)) {
                $val = trim(str_replace('-', '', $m[1]));
                if (str_contains($val, ',') && str_contains($val, '.')) {
                    $val = str_replace('.', '', $val);
                    $val = str_replace(',', '.', $val);
                } elseif (str_contains($val, ',')) {
                    $val = str_replace(',', '.', $val);
                }
                return round((float) $val, 2);
            }
            if ($required) {
                throw new InvalidArgumentException("No se encontró la línea requerida en el estado de cuenta ({$pattern}).");
            }
            return 0.0;
        };

        // 3. Montos estándar
        $netSales         = $parseNum('/Ventas netas\s+Bs\s*([0-9.,]+)/ui', $text, true);
        $deliveryFees     = $parseNum('/Env[íi]os a cargo del local\s+Bs\s*([0-9.,]+)/ui', $text);
        $serviceFees      = $parseNum('/Servicios PedidosYa\s+Bs\s*(-?[0-9.,]+)/ui', $text, true);
        $operatingCharges = $parseNum('/Cargos operativos\s+Bs\s*(-?[0-9.,]+)/ui', $text);
        $advertising      = $parseNum('/Publicidad dentro de la aplicaci[óo]n\s+Bs\s*(-?[0-9.,]+)/ui', $text);
        $refunds          = $parseNum('/Reintegros\s+Bs\s*(-?[0-9.,]+)/ui', $text);
        $taxes            = $parseNum('/Impuestos\s+Bs\s*(-?[0-9.,]+)/ui', $text);

        // 4. Total liquidado (usar modificador /s para abarcar saltos de línea)
        $totalPayout = null;
        if (preg_match('/Total liquidado del.*?Bs\s*([0-9.,]+)/uis', $text, $mTot)) {
            $totalPayout = $parseNum('/^([0-9.,]+)$/', $mTot[1]);
        } elseif (preg_match('/Total\s+Bs\.?\s*([0-9.,]+)/uis', $text, $mTot)) {
            $totalPayout = $parseNum('/^([0-9.,]+)$/', $mTot[1]);
        }

        if ($totalPayout === null) {
            throw new InvalidArgumentException("No se encontró el total liquidado en el estado de cuenta.");
        }

        // 5. Frenos de seguridad D6:
        // A) Comprobar líneas no contempladas por el sistema (deben ser 0.00)
        $adjustments     = $parseNum('/Ajustes de liquidaci[óo]n\s+Bs\s*(-?[0-9.,]+)/ui', $text);
        $outsidePayments = $parseNum('/Ventas con pago fuera de la App cobradas\s+Bs\s*(-?[0-9.,]+)/ui', $text);
        $pendingBalance  = $parseNum('/Saldo pendiente liquidaci[óo]n anterior\s+Bs\s*(-?[0-9.,]+)/ui', $text);
        $credit          = $parseNum('/Total Cr[ée]dito PedidosYa\s+Bs\.?\s*(-?[0-9.,]+)/ui', $text);

        if ($adjustments > 0.005 || $outsidePayments > 0.005 || $pendingBalance > 0.005 || $credit > 0.005) {
            throw new InvalidArgumentException("El estado de cuenta contiene conceptos no estándar (ajustes, créditos o saldos pendientes) que requieren revisión manual.");
        }

        // B) Comprobar fórmula: ventas netas − envíos − servicios − cargos − publicidad + reintegros − IVA = liquidado
        $expectedTotal = round($netSales - $deliveryFees - $serviceFees - $operatingCharges - $advertising + $refunds - $taxes, 2);
        if (abs($expectedTotal - $totalPayout) > 0.05) {
            throw new InvalidArgumentException("El estado de cuenta no cuadra: la suma de las líneas da Bs " . number_format($expectedTotal, 2) . " pero el total liquidado dice Bs " . number_format($totalPayout, 2) . ".");
        }

        return [
            'statement_number'  => $statementNumber,
            'start_date'        => $startDate,
            'end_date'          => $endDate,
            'payout_date'       => $payoutDate,
            'net_sales'         => $netSales,
            'delivery_fees'     => $deliveryFees,
            'service_fees'      => $serviceFees,
            'operating_charges' => $operatingCharges,
            'advertising'       => $advertising,
            'refunds'           => $refunds,
            'taxes'             => $taxes,
            'total_payout'      => $totalPayout,
        ];
    }
}
