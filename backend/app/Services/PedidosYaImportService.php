<?php

namespace App\Services;

use App\Models\PedidosYaSettlement;
use App\Models\PedidosYaOrder;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        $pedidosyaDiscountTotal = 0.0;
        $vendorDiscountTotal = 0.0;
        $firstOrderDate = null;
        $lastOrderDate = null;

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
            if (self::isDelivered($status)) {
                $csvGrossSales += $subtotal;
                $pedidosyaDiscountTotal += $pedidosyaDiscount;
                $vendorDiscountTotal += $vendorDiscount;
                $deliveredCount++;
            } elseif (mb_strtolower($status) === 'cancelado') {
                $cancelledCount++;
            }

            $csvCommission += $commission;
            $csvEstimatedIncome += $estimatedIncome;

            // Fechas
            $orderDateStr = $getVal('Fecha del pedido');
            $orderDate = $this->parseDate($orderDateStr, $timezone);
            if ($orderDate === null) {
                fclose($handle);
                throw new InvalidArgumentException("El pedido {$orderNumber} no tiene una fecha válida ('{$orderDateStr}').");
            }
            $firstOrderDate = $firstOrderDate === null || $orderDate < $firstOrderDate ? $orderDate : $firstOrderDate;
            $lastOrderDate = $lastOrderDate === null || $orderDate > $lastOrderDate ? $orderDate : $lastOrderDate;

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

        if (count($orders) === 0) {
            throw new InvalidArgumentException('El archivo CSV no tiene pedidos.');
        }

        // Semana sugerida: de lunes a domingo, la que contiene los pedidos del archivo
        $suggestedStart = Carbon::parse($firstOrderDate)->startOfWeek(Carbon::MONDAY)->toDateString();
        $suggestedEnd = Carbon::parse($lastOrderDate)->endOfWeek(Carbon::SUNDAY)->toDateString();

        return [
            'orders'               => $orders,
            'first_order_date'     => substr($firstOrderDate, 0, 10),
            'last_order_date'      => substr($lastOrderDate, 0, 10),
            'suggested_start_date' => $suggestedStart,
            'suggested_end_date'   => $suggestedEnd,
            'csv_pedidosya_discount' => round($pedidosyaDiscountTotal, 2),
            'csv_vendor_discount'  => round($vendorDiscountTotal, 2),
            'orders_count'         => count($orders),
            'delivered_count'      => $deliveredCount,
            'cancelled_count'      => $cancelledCount,
            'csv_gross_sales'      => round($csvGrossSales, 2),
            'csv_commission'       => round($csvCommission, 2),
            'csv_estimated_income' => round($csvEstimatedIncome, 2),
        ];
    }

    /**
     * Vista previa del archivo, sin guardar nada: semana sugerida, totales del CSV y conflictos.
     */
    public function preview(string|UploadedFile $csvFile, ?string $startDate = null, ?string $endDate = null): array
    {
        $csv = $this->parseCsv($csvFile);
        $start = $startDate ?: $csv['suggested_start_date'];
        $end = $endDate ?: $csv['suggested_end_date'];

        $existing = PedidosYaSettlement::whereDate('start_date', $start)->whereDate('end_date', $end)->first();

        return [
            'start_date'             => $start,
            'end_date'               => $end,
            'first_order_date'       => $csv['first_order_date'],
            'last_order_date'        => $csv['last_order_date'],
            'orders_count'           => $csv['orders_count'],
            'delivered_count'        => $csv['delivered_count'],
            'cancelled_count'        => $csv['cancelled_count'],
            'csv_gross_sales'        => $csv['csv_gross_sales'],
            'csv_commission'         => $csv['csv_commission'],
            'csv_estimated_income'   => $csv['csv_estimated_income'],
            'csv_pedidosya_discount' => $csv['csv_pedidosya_discount'],
            'csv_vendor_discount'    => $csv['csv_vendor_discount'],
            'orders_outside_range'   => $this->ordersOutsideRange($csv['orders'], $start, $end),
            'overlapping'            => $this->overlapping($start, $end)->map(fn ($st) => $this->weekLabel($st))->values()->all(),
            'replaces_settlement_id' => $existing?->id,
        ];
    }

    /**
     * Importa una semana completa guardando la liquidación y sus pedidos.
     *
     * - El rango sale del CSV (lunes a domingo) si no se indica.
     * - Una semana que se solapa con otra ya cargada se rechaza; la misma semana se reemplaza (reimportación).
     * - Un pedido que ya pertenece a otra semana se rechaza; los pedidos fuera del rango se avisan.
     *
     * @return array{settlement: PedidosYaSettlement, warnings: array<int, string>}
     */
    public function importSettlement(array $statementData, string|UploadedFile $csvFile, int $userId, ?string $zipPath = null): array
    {
        $csvResult = $this->parseCsv($csvFile);

        $startDate = Carbon::parse(!empty($statementData['start_date']) ? $statementData['start_date'] : $csvResult['suggested_start_date'])->toDateString();
        $endDate = Carbon::parse(!empty($statementData['end_date']) ? $statementData['end_date'] : $csvResult['suggested_end_date'])->toDateString();

        if ($endDate < $startDate) {
            throw ValidationException::withMessages([
                'end_date' => 'La fecha de fin no puede ser anterior a la de inicio.',
            ]);
        }

        $overlapping = $this->overlapping($startDate, $endDate);
        if ($overlapping->isNotEmpty()) {
            throw ValidationException::withMessages([
                'start_date' => 'La semana se cruza con otra ya cargada (' . $overlapping->map(fn ($st) => $this->weekLabel($st))->implode(', ')
                    . '). Elimina esa semana o corrige las fechas.',
            ]);
        }

        $sameWeek = PedidosYaSettlement::whereDate('start_date', $startDate)->whereDate('end_date', $endDate)->first();

        $orderNumbers = array_column($csvResult['orders'], 'order_number');
        $takenOrders = PedidosYaOrder::whereIn('order_number', $orderNumbers)
            ->whereHas('settlement')
            ->when($sameWeek, fn ($q) => $q->where('settlement_id', '!=', $sameWeek->id))
            ->with('settlement')
            ->get();

        if ($takenOrders->isNotEmpty()) {
            $first = $takenOrders->first();
            throw ValidationException::withMessages([
                'csv_file' => "El archivo trae {$takenOrders->count()} pedido(s) que ya pertenecen a otra semana (por ejemplo, "
                    . "{$first->order_number} en {$this->weekLabel($first->settlement)}).",
            ]);
        }

        $warnings = [];
        $outside = $this->ordersOutsideRange($csvResult['orders'], $startDate, $endDate);
        if (count($outside) > 0) {
            $warnings[] = count($outside) . ' pedido(s) del archivo tienen fecha fuera de la semana '
                . Carbon::parse($startDate)->format('d/m') . '–' . Carbon::parse($endDate)->format('d/m')
                . ': ' . implode(', ', array_slice($outside, 0, 5)) . (count($outside) > 5 ? '…' : '') . '.';
        }

        $netSales         = (float) $statementData['net_sales'];
        $deliveryFees     = (float) ($statementData['delivery_fees'] ?? 0);
        $serviceFees      = (float) $statementData['service_fees'];
        $operatingCharges = (float) ($statementData['operating_charges'] ?? 0);
        $advertising      = (float) ($statementData['advertising'] ?? 0);
        $refunds          = (float) ($statementData['refunds'] ?? 0);
        $taxes            = (float) ($statementData['taxes'] ?? 0);
        $totalPayout      = (float) $statementData['total_payout'];

        // Conciliación según §6.7:
        // Ventas netas - envíos - servicios - cargos - publicidad + reintegros - impuestos = total liquidado
        $calculatedPayout = round(
            $netSales - $deliveryFees - $serviceFees - $operatingCharges - $advertising + $refunds - $taxes,
            2
        );
        $discrepancy = round($totalPayout - $calculatedPayout, 2);

        $settlement = DB::transaction(function () use (
            $statementData,
            $csvResult,
            $userId,
            $zipPath,
            $startDate,
            $endDate,
            $sameWeek,
            $orderNumbers,
            $calculatedPayout,
            $discrepancy
        ) {
            $values = [
                'start_date'             => $startDate,
                'end_date'               => $endDate,
                'payout_date'            => $statementData['payout_date'] ?? null,
                'net_sales'              => $statementData['net_sales'],
                'delivery_fees'          => $statementData['delivery_fees'] ?? 0,
                'service_fees'           => $statementData['service_fees'],
                'operating_charges'      => $statementData['operating_charges'] ?? 0,
                'advertising'            => $statementData['advertising'] ?? 0,
                'refunds'                => $statementData['refunds'] ?? 0,
                'taxes'                  => $statementData['taxes'] ?? 0,
                'total_payout'           => $statementData['total_payout'],
                'calculated_payout'      => $calculatedPayout,
                'discrepancy'            => $discrepancy,
                'orders_count'           => $csvResult['orders_count'],
                'delivered_count'        => $csvResult['delivered_count'],
                'cancelled_count'        => $csvResult['cancelled_count'],
                'csv_gross_sales'        => $csvResult['csv_gross_sales'],
                'csv_commission'         => $csvResult['csv_commission'],
                'csv_estimated_income'   => $csvResult['csv_estimated_income'],
                'csv_pedidosya_discount' => $csvResult['csv_pedidosya_discount'],
                'csv_vendor_discount'    => $csvResult['csv_vendor_discount'],
                'notes'                  => $statementData['notes'] ?? null,
                'user_id'                => $userId,
            ];

            // Al reimportar sin ZIP se conserva el adjunto anterior
            if ($zipPath !== null || $sameWeek === null) {
                $values['zip_path'] = $zipPath;
            }

            if ($sameWeek) {
                $sameWeek->update($values);
                $settlement = $sameWeek;

                // Pedidos que ya no vienen en el archivo nuevo
                $settlement->orders()->whereNotIn('order_number', $orderNumbers)->delete();
            } else {
                $settlement = PedidosYaSettlement::create($values);
            }

            // Guardar o actualizar pedidos (idempotencia por order_number)
            foreach ($csvResult['orders'] as $orderData) {
                PedidosYaOrder::updateOrCreate(
                    ['order_number' => $orderData['order_number']],
                    array_merge($orderData, ['settlement_id' => $settlement->id])
                );
            }

            return $settlement->fresh(['orders']);
        });

        return ['settlement' => $settlement, 'warnings' => $warnings];
    }

    public static function isDelivered(?string $status): bool
    {
        return mb_strtolower(trim((string) $status)) === 'entregado';
    }

    /** Semanas ya cargadas que se cruzan con el rango (sin contar la misma semana exacta). */
    protected function overlapping(string $startDate, string $endDate)
    {
        // Las columnas `date` se guardan como 'AAAA-MM-DD 00:00:00'
        return PedidosYaSettlement::where('start_date', '<=', $endDate . ' 23:59:59')
            ->where('end_date', '>=', $startDate)
            ->whereNot(function ($q) use ($startDate, $endDate) {
                $q->whereDate('start_date', $startDate)->whereDate('end_date', $endDate);
            })
            ->orderBy('start_date')
            ->get();
    }

    /** Números de pedido cuya fecha cae fuera del rango. */
    protected function ordersOutsideRange(array $orders, string $startDate, string $endDate): array
    {
        return array_values(array_map(
            fn ($o) => $o['order_number'],
            array_filter($orders, function ($o) use ($startDate, $endDate) {
                $day = substr((string) $o['order_date'], 0, 10);
                return $day < $startDate || $day > $endDate;
            })
        ));
    }

    protected function weekLabel(?PedidosYaSettlement $st): string
    {
        if (!$st) {
            return 'otra semana';
        }

        return $st->start_date->format('d/m/Y') . '–' . $st->end_date->format('d/m/Y');
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
