<?php

namespace App\Services\PedidosYa;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use ZipArchive;

class OrderListReader
{
    /**
     * Lee un archivo Excel (invoice-<N>.xls, formato OOXML) de PedidosYa.
     */
    public function read(string|UploadedFile $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (!file_exists($path) || !is_readable($path)) {
            throw new InvalidArgumentException("El archivo Excel de pedidos no existe o no se puede leer.");
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException("No se pudo abrir el archivo Excel (formato no compatible).");
        }

        // 1. Cargar strings compartidas (sharedStrings.xml)
        $sharedStrings = [];
        $sstXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sstXml) {
            $sst = simplexml_load_string($sstXml);
            if ($sst && isset($sst->si)) {
                foreach ($sst->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string) $si->t;
                    } elseif (isset($si->r)) {
                        $str = '';
                        foreach ($si->r as $r) {
                            $str .= (string) $r->t;
                        }
                        $sharedStrings[] = $str;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Mapear hojas desde xl/workbook.xml
        $wbXml = simplexml_load_string($zip->getFromName('xl/workbook.xml') ?: '');
        $sheetFiles = [];
        if ($wbXml && isset($wbXml->sheets->sheet)) {
            $relsXml = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels') ?: '');
            $rIdToTarget = [];
            if ($relsXml && isset($relsXml->Relationship)) {
                foreach ($relsXml->Relationship as $rel) {
                    $rIdToTarget[(string) $rel['Id']] = (string) $rel['Target'];
                }
            }

            foreach ($wbXml->sheets->sheet as $s) {
                $name = (string) $s['name'];
                $rId = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $target = $rIdToTarget[$rId] ?? null;
                if ($target) {
                    $sheetPath = str_starts_with($target, 'worksheets/') ? 'xl/' . $target : 'xl/worksheets/' . basename($target);
                    $sheetFiles[$name] = $sheetPath;
                }
            }
        }

        // Helper para leer filas de un XML de hoja
        $readRows = function (?string $content) use ($sharedStrings): array {
            if (!$content) {
                return [];
            }
            $xml = simplexml_load_string($content);
            if (!$xml || !isset($xml->sheetData->row)) {
                return [];
            }
            $rows = [];
            foreach ($xml->sheetData->row as $row) {
                $r = [];
                foreach ($row->c as $c) {
                    $t = (string) $c['t'];
                    $val = isset($c->v) ? (string) $c->v : '';
                    if ($t === 's' && isset($sharedStrings[(int) $val])) {
                        $val = $sharedStrings[(int) $val];
                    }
                    $r[] = $val;
                }
                $rows[] = $r;
            }
            return $rows;
        };

        // Fallbacks para las rutas de hoja
        $ordersSheetPath = $sheetFiles['Lista de Pedidos'] ?? 'xl/worksheets/sheet1.xml';
        $claimsSheetPath = $sheetFiles['Cargos por reclamos'] ?? 'xl/worksheets/sheet2.xml';

        $orderRows = $readRows($zip->getFromName($ordersSheetPath));
        $claimRows = $readRows($zip->getFromName($claimsSheetPath));

        $zip->close();

        // 3. Procesar pedidos (hoja Lista de Pedidos)
        $orders = [];
        if (count($orderRows) > 1) {
            $headers = array_map(fn ($h) => trim((string) $h), $orderRows[0]);
            $headerMap = array_flip($headers);

            for ($i = 1; $i < count($orderRows); $i++) {
                $row = $orderRows[$i];
                if (empty($row) || empty($row[0])) {
                    continue;
                }

                $getVal = fn ($col) => isset($headerMap[$col], $row[$headerMap[$col]]) ? trim((string) $row[$headerMap[$col]]) : '';

                $orderNum = $getVal('Número de pedido');
                if (empty($orderNum)) {
                    continue;
                }

                $dateRaw = $getVal('Fecha de pedido');
                $dateFormatted = null;
                if (!empty($dateRaw)) {
                    try {
                        $dateFormatted = Carbon::createFromFormat('d/m/Y', $dateRaw)->toDateString();
                    } catch (\Throwable) {
                        $dateFormatted = substr($dateRaw, 0, 10);
                    }
                }

                $orders[] = [
                    'order_number'          => $orderNum,
                    'status'                => $getVal('Order Status') ?: 'Entregado',
                    'order_date'            => $dateFormatted,
                    'gross_amount'          => (float) ($getVal('Monto bruto de la venta') ?: 0),
                    'net_amount'            => (float) ($getVal('Monto neto de la Venta ($)') ?: 0),
                    'commissionable_amount' => (float) ($getVal('Monto comisionable Servicios PedidosYa') ?: 0),
                    'commission_amount'     => (float) ($getVal('Servicio Ventas PedidoYa ($)') ?: 0),
                    'commission_rate'       => $getVal('Servicio Ventas PedidosYA (%)'),
                    'payment_method'        => $getVal('Método de pago'),
                    'collected_by'          => $getVal('Cobrado por'),
                    'delivery_method'       => $getVal('Método de entrega'),
                ];
            }
        }

        // 4. Procesar reclamos (hoja Cargos por reclamos si existe)
        $claims = [];
        if (count($claimRows) > 1) {
            $claimHeaders = array_map(fn ($h) => trim((string) $h), $claimRows[0]);
            $claimMap = array_flip($claimHeaders);

            for ($i = 1; $i < count($claimRows); $i++) {
                $row = $claimRows[$i];
                if (empty($row) || empty($row[0])) {
                    continue;
                }

                $getClaimVal = fn ($col) => isset($claimMap[$col], $row[$claimMap[$col]]) ? trim((string) $row[$claimMap[$col]]) : '';

                $orderNum = $getClaimVal('Número de pedido');
                if (empty($orderNum)) {
                    continue;
                }

                $dateRaw = $getClaimVal('Fecha del pedido');
                $dateFormatted = null;
                if (!empty($dateRaw)) {
                    try {
                        $dateFormatted = Carbon::createFromFormat('d/m/Y', $dateRaw)->toDateString();
                    } catch (\Throwable) {
                        $dateFormatted = substr($dateRaw, 0, 10);
                    }
                }

                $claims[] = [
                    'order_number' => $orderNum,
                    'status'       => $getClaimVal('Estado del pedido'),
                    'date'         => $dateFormatted,
                    'time'         => $getClaimVal('Hora'),
                    'reason'       => $getClaimVal('Motivo'),
                    'comments'     => $getClaimVal('Comentarios de los usuarios'),
                    'order_amount' => (float) ($getClaimVal('Monto del Pedido') ?: 0),
                    'charge'       => abs((float) ($getClaimVal('Cargos por reclamos de los usuarios') ?: 0)),
                ];
            }
        }

        $grossSales = round(array_sum(array_column($orders, 'gross_amount')), 2);
        $netSales = round(array_sum(array_column($orders, 'net_amount')), 2);
        $totalCommission = round(array_sum(array_column($orders, 'commission_amount')), 2);
        $totalClaims = round(array_sum(array_column($claims, 'charge')), 2);

        return [
            'orders'       => $orders,
            'claims'       => $claims,
            'orders_count' => count($orders),
            'claims_count' => count($claims),
            'gross_sales'  => $grossSales,
            'net_sales'    => $netSales,
            'commission'   => $totalCommission,
            'total_claims' => $totalClaims,
        ];
    }
}
