<?php

namespace App\Services;

use App\Models\PedidosYaSettlement;
use App\Models\PedidosYaOrder;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PedidosYaImportService
{
    /**
     * Columnas obligatorias del CSV según §6.2 del Plan de Finanzas
     */
    protected array $requiredColumns = [
        'Nro de pedido',
        'Estado del pedido',
        'Fecha del pedido',
        'Forma de pago',
        'Tipo de entrega',
        'Total parcial',
        'Comisión',
        'Impuesto',
        'Descuento financiado por usted',
        'Descuento financiado por PedidosYa',
        'Ingreso estimado',
        'Artículos del pedido',
    ];

    /**
     * Parsea un archivo CSV de PedidosYa y extrae los pedidos estructurados.
     * Soporta BOM UTF-8 y decimales con coma (ej: "60,00" o "1.234,50").
     */
    public function parseCsv(string|UploadedFile $filePath): array
    {
        $realPath = $filePath instanceof UploadedFile ? $filePath->getRealPath() : $filePath;

        if (!file_exists($realPath) || !is_readable($realPath)) {
            throw new InvalidArgumentException("El archivo CSV no existe o no se puede leer.");
        }

        $handle = fopen($realPath, 'r');
        if (!$handle) {
            throw new InvalidArgumentException("No se pudo abrir el archivo CSV.");
        }

        // Leer primera línea (cabeceras) y remover BOM si existe
        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);
            throw new InvalidArgumentException("El archivo CSV está vacío.");
        }

        // Remover BOM (\xEF\xBB\xBF)
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);

        // Parsear cabeceras con str_getcsv
        $headers = str_getcsv($headerLine, separator: ',', enclosure: '"', escape: '\\');
        $headers = array_map(fn($h) => trim($h), $headers);

        // Mapeo de nombre de columna a índice
        $headerMap = [];
        foreach ($headers as $idx => $headerName) {
            $headerMap[$headerName] = $idx;
        }

        // Verificar columnas obligatorias
        foreach ($this->requiredColumns as $col) {
            if (!isset($headerMap[$col])) {
                fclose($handle);
                throw new InvalidArgumentException("El archivo CSV no contiene la columna obligatoria: '{$col}'.");
            }
        }

        $orders = [];
        $csvGrossSales = 0.0;
        $csvCommission = 0.0;
        $csvEstimatedIncome = 0.0;
        $deliveredCount = 0;
        $cancelledCount = 0;

        $timezone = config('app.timezone', 'America/La_Paz');

        while (($row = fgetcsv($handle, separator: ',', enclosure: '"', escape: '\\')) !== false) {
            // Ignorar líneas vacías
            if (count($row) === 1 && empty($row[0])) {
                continue;
            }

            // Mapear campos por nombre
            $getVal = function(string $col) use ($row, $headerMap) {
                $idx = $headerMap[$col] ?? null;
                return ($idx !== null && isset($row[$idx])) ? trim($row[$idx]) : '';
            };

            $orderNumber = $getVal('Nro de pedido');
            if (empty($orderNumber)) {
                continue;
            }

            $status = $getVal('Estado del pedido');
            $subtotal = $this->parseNumber($getVal('Total parcial'));
            $commission = $this->parseNumber($getVal('Comisión'));
            $tax = $this->parseNumber($getVal('Impuesto'));
            $vendorDiscount = $this->parseNumber($getVal('Descuento financiado por usted'));
            $pedidosyaDiscount = $this->parseNumber($getVal('Descuento financiado por PedidosYa'));
            $estimatedIncome = $this->parseNumber($getVal('Ingreso estimado'));

            // Acumular estadísticas del CSV
            if (mb_strtolower($status) === 'entregado') {
                $csvGrossSales += $subtotal;
                $deliveredCount++;
            } elseif (mb_strtolower($status) === 'cancelado') {
                $cancelledCount++;
            }

            $csvCommission += $commission;
            $csvEstimatedIncome += $estimatedIncome;

            // Fechas
            $orderDateStr = $getVal('Fecha del pedido');
            $orderDate = $this->parseDate($orderDateStr, $timezone);

            // Construir raw_data completo asociativo
            $rawAssoc = [];
            foreach ($headers as $idx => $hName) {
                $rawAssoc[$hName] = $row[$idx] ?? '';
            }

            $orders[] = [
                'order_number'        => $orderNumber,
                'status'              => $status,
                'order_date'          => $orderDate,
                'accepted_at'         => $this->parseDate($getVal('Aceptado en'), $timezone),
                'ready_at'            => $this->parseDate($getVal('Lista para retiro'), $timezone),
                'pickup_at'           => $this->parseDate($getVal('Retirado del local el'), $timezone),
                'delivered_at'        => $this->parseDate($getVal('Entregado el'), $timezone),
                'cancelled_at'        => $this->parseDate($getVal('Cancelado el'), $timezone),
                'payment_method'      => $getVal('Forma de pago'),
                'delivery_type'       => $getVal('Tipo de entrega'),
                'subtotal'            => $subtotal,
                'commission'          => $commission,
                'tax'                 => $tax,
                'vendor_discount'     => $vendorDiscount,
                'pedidosya_discount'  => $pedidosyaDiscount,
                'estimated_income'    => $estimatedIncome,
                'items_text'          => $getVal('Artículos del pedido'),
                'cancellation_reason' => $getVal('Motivo de rechazo') ?: $getVal('Motivo del reclamo'),
                'raw_data'            => $rawAssoc,
            ];
        }

        fclose($handle);

        return [
            'orders'               => $orders,
            'orders_count'         => count($orders),
            'delivered_count'      => $deliveredCount,
            'cancelled_count'      => $cancelledCount,
            'csv_gross_sales'      => round($csvGrossSales, 2),
            'csv_commission'       => round($csvCommission, 2),
            'csv_estimated_income' => round($csvEstimatedIncome, 2),
        ];
    }

    /**
     * Importa una semana completa guardando la liquidación y sus pedidos correspondientes.
     */
    public function importSettlement(array $statementData, string|UploadedFile $csvFile, int $userId, ?string $zipPath = null): PedidosYaSettlement
    {
        $csvResult = $this->parseCsv($csvFile);

        $netSales         = (float) $statementData['net_sales'];
        $deliveryFees     = (float) ($statementData['delivery_fees'] ?? 0);
        $serviceFees      = (float) $statementData['service_fees'];
        $operatingCharges = (float) ($statementData['operating_charges'] ?? 0);
        $advertising      = (float) ($statementData['advertising'] ?? 0);
        $refunds          = (float) ($statementData['refunds'] ?? 0);
        $taxes            = (float) ($statementData['taxes'] ?? 0);
        $totalPayout      = (float) $statementData['total_payout'];

        // Conciliación según §6.3:
        // Ventas netas - envíos - servicios - cargos - publicidad + reintegros - impuestos = total liquidado
        $calculatedPayout = round(
            $netSales - $deliveryFees - $serviceFees - $operatingCharges - $advertising + $refunds - $taxes,
            2
        );
        $discrepancy = round($totalPayout - $calculatedPayout, 2);

        return DB::transaction(function () use (
            $statementData,
            $csvResult,
            $userId,
            $zipPath,
            $calculatedPayout,
            $discrepancy
        ) {
            // Guardar o actualizar la liquidación para ese rango de fechas
            $settlement = PedidosYaSettlement::updateOrCreate(
                [
                    'start_date' => $statementData['start_date'],
                    'end_date'   => $statementData['end_date'],
                ],
                [
                    'payout_date'          => $statementData['payout_date'] ?? null,
                    'net_sales'            => $statementData['net_sales'],
                    'delivery_fees'        => $statementData['delivery_fees'] ?? 0,
                    'service_fees'         => $statementData['service_fees'],
                    'operating_charges'    => $statementData['operating_charges'] ?? 0,
                    'advertising'          => $statementData['advertising'] ?? 0,
                    'refunds'              => $statementData['refunds'] ?? 0,
                    'taxes'                => $statementData['taxes'] ?? 0,
                    'total_payout'         => $statementData['total_payout'],
                    'calculated_payout'    => $calculatedPayout,
                    'discrepancy'          => $discrepancy,
                    'orders_count'         => $csvResult['orders_count'],
                    'delivered_count'      => $csvResult['delivered_count'],
                    'cancelled_count'      => $csvResult['cancelled_count'],
                    'csv_gross_sales'      => $csvResult['csv_gross_sales'],
                    'csv_commission'       => $csvResult['csv_commission'],
                    'csv_estimated_income' => $csvResult['csv_estimated_income'],
                    'zip_path'             => $zipPath,
                    'notes'                => $statementData['notes'] ?? null,
                    'user_id'              => $userId,
                ]
            );

            // Guardar o actualizar pedidos (idempotencia por order_number)
            foreach ($csvResult['orders'] as $orderData) {
                PedidosYaOrder::updateOrCreate(
                    ['order_number' => $orderData['order_number']],
                    array_merge($orderData, ['settlement_id' => $settlement->id])
                );
            }

            return $settlement->fresh(['orders']);
        });
    }

    /**
     * Limpia y convierte números con posibles comas decimales y puntos de miles.
     */
    public function parseNumber(string $raw): float
    {
        $val = trim($raw);
        if ($val === '') {
            return 0.0;
        }

        // Quitar comillas y espacios
        $val = str_replace(['"', "'", ' '], '', $val);

        // Si tiene formato "1.234,50" -> quitar puntos y cambiar coma por punto
        if (str_contains($val, ',') && str_contains($val, '.')) {
            $val = str_replace('.', '', $val);
            $val = str_replace(',', '.', $val);
        } elseif (str_contains($val, ',')) {
            // Formato "60,00"
            $val = str_replace(',', '.', $val);
        }

        return round((float) $val, 2);
    }

    /**
     * Parsea strings de fecha a Carbon o null.
     */
    protected function parseDate(string $raw, string $timezone): ?string
    {
        $val = trim($raw);
        if (empty($val)) {
            return null;
        }

        try {
            // Formato estándar PedidosYa: 'AAAA-MM-DD HH:MM' o 'AAAA-MM-DD HH:MM:SS'
            return Carbon::parse($val, $timezone)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
