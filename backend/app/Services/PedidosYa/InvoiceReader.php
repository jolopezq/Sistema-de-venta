<?php

namespace App\Services\PedidosYa;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Smalot\PdfParser\Parser;

class InvoiceReader
{
    public function __construct(
        protected ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? new Parser();
    }

    /**
     * Lee un archivo PDF de factura emitida por PedidosYa.
     */
    public function read(string|UploadedFile $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (!file_exists($path) || !is_readable($path)) {
            throw new InvalidArgumentException("El archivo PDF de la factura no existe o no se puede leer.");
        }

        $pdf = $this->parser->parseFile($path);
        $text = $pdf->getText();

        return $this->interpret($text);
    }

    /**
     * Interpreta el texto extraído del PDF de la factura.
     */
    public function interpret(string $text): array
    {
        // 1. Número de factura: FACTURA Nº 688161
        if (!preg_match('/FACTURA\s+N[º°]\s*(\d+)/ui', $text, $mNum)) {
            throw new InvalidArgumentException("No se encontró el número de factura.");
        }
        $invoiceNumber = $mNum[1];

        // 2. Código de autorización
        $invoiceAuth = null;
        if (preg_match('/COD\.\s*AUTORIZACI[ÓO]N\s*\n([A-F0-9\s]+)\nFACTURA/ui', $text, $mAuth)) {
            $invoiceAuth = preg_replace('/\s+/', '', $mAuth[1]);
        }

        // 3. Fecha de factura: Fecha: 28/09/2026 4:52 PM
        if (!preg_match('/Fecha:\s*(\d{2}\/\d{2}\/\d{4})/ui', $text, $mDate)) {
            throw new InvalidArgumentException("No se encontró la fecha de emisión de la factura.");
        }
        $invoiceDate = Carbon::createFromFormat('d/m/Y', $mDate[1])->toDateString();

        // Helper para números
        $parseNum = function (string $pattern, string $content): ?float {
            if (preg_match($pattern, $content, $m)) {
                $val = trim(str_replace(['"', "'", ' '], '', $m[1]));
                if (str_contains($val, ',') && str_contains($val, '.')) {
                    $val = str_replace('.', '', $val);
                    $val = str_replace(',', '.', $val);
                } elseif (str_contains($val, ',')) {
                    $val = str_replace(',', '.', $val);
                }
                return round((float) $val, 2);
            }
            return null;
        };

        // 4. Total a pagar e importe base crédito fiscal
        $invoiceTotal = $parseNum('/TOTAL\s+A\s+PAGAR\s+Bs\s*([\d.,]+)/ui', $text)
            ?? $parseNum('/TOTAL\s+Bs\s*([\d.,]+)/ui', $text)
            ?? 0.0;

        $fiscalCreditBase = $parseNum('/IMPORTE\s+BASE\s+CR[ÉE]DITO\s+FISCAL\s*([\d.,]+)/ui', $text)
            ?? $invoiceTotal;

        return [
            'invoice_number'        => $invoiceNumber,
            'invoice_date'          => $invoiceDate,
            'invoice_authorization' => $invoiceAuth,
            'invoice_total'         => $invoiceTotal,
            'fiscal_credit_base'    => $fiscalCreditBase,
        ];
    }
}
