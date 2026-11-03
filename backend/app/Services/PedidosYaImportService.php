<?php

namespace App\Services;

use App\Models\PedidosYaSettlement;
use App\Models\PedidosYaOrder;
use App\Services\PedidosYa\SalesLinker;
use App\Services\PedidosYa\StatementReader;
use App\Services\PedidosYa\OrderListReader;
use App\Services\PedidosYa\InvoiceReader;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PedidosYaImportService
{
    public function __construct(
        protected ?StatementReader $statementReader = null,
        protected ?OrderListReader $orderListReader = null,
        protected ?InvoiceReader $invoiceReader = null,
        protected ?SalesLinker $salesLinker = null,
    ) {
        $this->statementReader = $statementReader ?? app(StatementReader::class);
        $this->orderListReader = $orderListReader ?? app(OrderListReader::class);
        $this->invoiceReader   = $invoiceReader   ?? app(InvoiceReader::class);
        $this->salesLinker     = $salesLinker     ?? app(SalesLinker::class);
    }

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

        // Detectar delimitador (, o ; o \t)
        $delimiter = $this->detectDelimiter($headerLine);

        // Parsear cabeceras con str_getcsv
        $headers = str_getcsv($headerLine, separator: $delimiter, enclosure: '"', escape: '\\');
        $headers = array_map(function ($h) {
            $cleaned = preg_replace('/^\xEF\xBB\xBF/', '', trim($h));
            return trim($cleaned, "\"'\t\n\r \v\0");
        }, $headers);

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

        while (($row = fgetcsv($handle, separator: $delimiter, enclosure: '"', escape: '\\')) !== false) {
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
            // Ventas que se crearán o vincularán (§6.2): sin las líneas internas para el registro
            'sales'                  => array_diff_key(app(SalesLinker::class)->analyze($csv['orders'], $start, $end), ['payloads' => true]),
        ];
    }

    /**
     * Importa una semana completa guardando la liquidación y sus pedidos.
     *
     * - El rango sale del CSV (lunes a domingo) si no se indica.
     * - Una semana que se solapa con otra ya cargada se rechaza; la misma semana se reemplaza (reimportación).
     * - Un pedido que ya pertenece a otra semana se rechaza; los pedidos fuera del rango se avisan.
     *
     * Con `$salesMode = 'create'` además crea o vincula las ventas de los pedidos entregados (§6.3); se rechaza
     * si quedan artículos sin equivalencia en el POS. Con 'skip' solo guarda los montos y los pedidos.
     *
     * @param array $links Elección del dueño por pedido: [order_number => sale_id | 'new']
     * @return array{settlement: PedidosYaSettlement, warnings: array<int, string>, sales: ?array}
     */
    public function importSettlement(array $statementData, string|UploadedFile $csvFile, int $userId, ?string $zipPath = null, string $salesMode = 'skip', array $links = []): array
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

        $linker = app(SalesLinker::class);
        if ($salesMode === 'create') {
            $analysis = $linker->analyze($csvResult['orders'], $startDate, $endDate, $links);
            if ($analysis['summary']['unresolved_names'] > 0) {
                $names = collect($analysis['unresolved']['products'])->pluck('name')
                    ->merge(collect($analysis['unresolved']['options'])->pluck('name'))
                    ->implode(', ');
                throw ValidationException::withMessages([
                    'mappings' => "Hay artículos de PedidosYa sin equivalencia en el POS: {$names}. Asígnalos o importa solo los montos.",
                ]);
            }
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

        $salesResult = null;
        $removedWithSale = [];
        $previousZip = $sameWeek?->zip_path;
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
            $discrepancy,
            $salesMode,
            $links,
            $linker,
            &$salesResult,
            &$removedWithSale
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

                // Pedidos que ya no vienen en el archivo nuevo. Sus ventas no se anulan solas: se avisa.
                $removed = $settlement->orders()->whereNotIn('order_number', $orderNumbers)->with('sale:id,order_number,status')->get();
                foreach ($removed->filter(fn ($o) => $o->sale && $o->sale->status === 'completed') as $o) {
                    $removedWithSale[] = "{$o->order_number} (venta #{$o->sale->order_number})";
                }
                $settlement->orders()->whereNotIn('order_number', $orderNumbers)->delete();
            } else {
                $settlement = PedidosYaSettlement::create($values);
            }

            // Guardar o actualizar pedidos (idempotencia por order_number; la venta vinculada se conserva)
            foreach ($csvResult['orders'] as $orderData) {
                PedidosYaOrder::updateOrCreate(
                    ['order_number' => $orderData['order_number']],
                    array_merge($orderData, ['settlement_id' => $settlement->id])
                );
            }

            if ($salesMode === 'create') {
                $salesResult = $linker->sync($settlement, $links, $userId);
            }

            return $settlement->fresh(['orders']);
        });

        if ($zipPath !== null && $previousZip && $previousZip !== $zipPath) {
            Storage::delete($previousZip);
        }

        if (count($removedWithSale) > 0) {
            $warnings[] = 'Estos pedidos ya no están en el archivo y conservan su venta: ' . implode(', ', $removedWithSale)
                . '. Anúlalas en Control Diario si corresponde.';
        }

        if ($salesResult && count($salesResult['errors']) > 0) {
            $warnings[] = count($salesResult['errors']) . ' pedido(s) no se pudieron registrar como venta: ' . implode(' ', $salesResult['errors']);
        }

        return ['settlement' => $settlement, 'warnings' => $warnings, 'sales' => $salesResult];
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

    /**
     * Detecta el delimitador más probable del CSV (coma, punto y coma o tabulador).
     */
    public function detectDelimiter(string $headerLine): string
    {
        $candidates = [',', ';', "\t"];
        $best = ',';
        $max = 0;
        foreach ($candidates as $candidate) {
            $count = substr_count($headerLine, $candidate);
            if ($count > $max) {
                $max = $count;
                $best = $candidate;
            }
        }
        return $best;
    }

    /**
     * Determina el estado de una semana frente a liquidaciones ya registradas (D3).
     */
    public function determineWeekStatus(string $startDate, string $endDate, float $totalPayout, ?string $statementNumber): array
    {
        // 1. Ya importada por número de liquidación
        if (!empty($statementNumber)) {
            $byNumber = PedidosYaSettlement::where('statement_number', $statementNumber)->first();
            if ($byNumber) {
                return [
                    'status'              => 'already_imported',
                    'existing_settlement' => $byNumber,
                ];
            }
        }

        // 2. Misma semana exacta (fechas iguales)
        $sameDates = PedidosYaSettlement::whereDate('start_date', $startDate)
            ->whereDate('end_date', $endDate)
            ->first();

        if ($sameDates) {
            $diff = abs((float) $sameDates->total_payout - $totalPayout);
            if ($diff < 0.05) {
                return [
                    'status'              => 'same_dates_same_amounts',
                    'existing_settlement' => $sameDates,
                ];
            }
            return [
                'status'              => 'same_dates_diff_amounts',
                'existing_settlement' => $sameDates,
            ];
        }

        // 3. Se solapa con otra semana existente
        $overlapping = $this->overlapping($startDate, $endDate);
        if ($overlapping->isNotEmpty()) {
            return [
                'status'              => 'overlaps',
                'existing_settlement' => $overlapping->first(),
            ];
        }

        // 4. Nueva
        return [
            'status'              => 'new',
            'existing_settlement' => null,
        ];
    }

    /**
     * Valida los frenos de seguridad entre PDF, Excel y factura (D6).
     */
    public function validateSafetyControls(array $statement, ?array $xls, ?array $invoice): array
    {
        $controls = [
            'balance_ok'           => true,
            'standard_lines_ok'    => true,
            'xls_sales_match'      => null,
            'xls_commission_match' => null,
            'xls_claims_match'     => null,
            'invoice_match'        => null,
        ];
        $blockers = [];
        $warnings = [];

        if ($xls) {
            if (abs($xls['net_sales'] - $statement['net_sales']) > 0.05) {
                $controls['xls_sales_match'] = false;
                $blockers[] = "El Excel de pedidos no coincide con el estado de cuenta en ventas netas (Excel: Bs " . number_format($xls['net_sales'], 2) . ", PDF: Bs " . number_format($statement['net_sales'], 2) . ").";
            } else {
                $controls['xls_sales_match'] = true;
            }

            if (abs($xls['commission'] - $statement['service_fees']) > 0.05) {
                $controls['xls_commission_match'] = false;
                $blockers[] = "El Excel de pedidos no coincide con el estado de cuenta en comisiones (Excel: Bs " . number_format($xls['commission'], 2) . ", PDF: Bs " . number_format($statement['service_fees'], 2) . ").";
            } else {
                $controls['xls_commission_match'] = true;
            }

            if (abs($xls['total_claims'] - $statement['operating_charges']) > 0.05) {
                $controls['xls_claims_match'] = false;
                $blockers[] = "Los reclamos del Excel (Bs " . number_format($xls['total_claims'], 2) . ") no coinciden con los cargos operativos del PDF (Bs " . number_format($statement['operating_charges'], 2) . ").";
            } else {
                $controls['xls_claims_match'] = true;
            }
        }

        if ($invoice) {
            $expectedInvoice = round(
                $statement['service_fees'] + $statement['operating_charges'] + $statement['advertising'] + $statement['taxes'],
                2
            );
            // La factura descuenta el descuento de PedidosYa a clientes (parte de los reintegros), no el reintegro por
            // pedidos cancelados. Si el estado de cuenta no detalla los reintegros, se prueba con el total.
            $candidates = [
                $expectedInvoice,
                round($expectedInvoice - (float) ($statement['refunds_peya_discount'] ?? 0), 2),
                round($expectedInvoice - (float) $statement['refunds'], 2),
            ];
            $matches = collect($candidates)->contains(fn ($v) => abs($v - $invoice['invoice_total']) <= 0.05);
            if ($expectedInvoice !== $candidates[1]) {
                $expectedInvoice = $candidates[1];
            }
            if (!$matches) {
                $controls['invoice_match'] = false;
                $warnings[] = "El total de la factura fiscal (Bs " . number_format($invoice['invoice_total'], 2) . ") difiere de la suma de cargos facturables del estado de cuenta (Bs " . number_format($expectedInvoice, 2) . ").";
            } else {
                $controls['invoice_match'] = true;
            }
        }

        return [
            'controls' => $controls,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * Lee e interpreta los archivos de una semana sin guardarlos (previsualización).
     *
     * Además del estado frente a lo ya cargado (D3) y los controles (D6), arma el plan de pedidos de la semana:
     * los del CSV (con artículos y hora) y, si al CSV le faltan pedidos de la liquidación, los del Excel sin
     * artículos. Ningún archivo que no se pudo leer pasa en silencio: queda como aviso.
     */
    public function readWeek(
        UploadedFile|string $statementFile,
        UploadedFile|string|null $ordersXls = null,
        UploadedFile|string|null $invoicePdf = null,
        UploadedFile|string|array|null $csvFile = null,
        ?string $statementFilename = null,
        ?string $statementNumber = null,
        array $links = []
    ): array {
        $filename = $statementFilename ?? ($statementFile instanceof UploadedFile ? $statementFile->getClientOriginalName() : basename($statementFile));

        $statement = null;
        $readerError = null;
        try {
            $statement = $this->statementReader->read($statementFile, $filename);
        } catch (\Throwable $e) {
            $readerError = $e->getMessage();
        }

        if ($statement === null) {
            return [
                'statement_number'    => $statementNumber,
                'start_date'          => null,
                'end_date'            => null,
                'payout_date'         => null,
                'status'              => 'blocked',
                'existing_settlement' => null,
                'statement'           => null,
                'invoice'             => null,
                'claims'              => [],
                'claims_count'        => 0,
                'orders_count'        => 0,
                'orders_source'       => null,
                'controls'            => [
                    'balance_ok'           => false,
                    'standard_lines_ok'    => false,
                    'xls_sales_match'      => null,
                    'xls_commission_match' => null,
                    'xls_claims_match'     => null,
                    'invoice_match'        => null,
                ],
                'warnings'            => [],
                'blockers'            => [$readerError ?: 'No se pudo leer el PDF del estado de cuenta.'],
                'sales'               => null,
                'orders_plan'         => [],
            ];
        }

        if (empty($statement['statement_number']) && !empty($statementNumber)) {
            $statement['statement_number'] = $statementNumber;
        } else {
            $statementNumber = $statement['statement_number'];
        }

        $statusInfo = $this->determineWeekStatus(
            $statement['start_date'],
            $statement['end_date'],
            (float) $statement['total_payout'],
            $statementNumber
        );
        $status = $statusInfo['status'];
        $existingSettlement = $statusInfo['existing_settlement'];

        $fileWarnings = [];

        $invoice = null;
        if ($invoicePdf) {
            try {
                $invoice = $this->invoiceReader->read($invoicePdf);
            } catch (\Throwable $e) {
                $fileWarnings[] = "No se pudo leer la factura fiscal ({$e->getMessage()}): la semana se guarda sin sus datos.";
            }
        } else {
            $fileWarnings[] = 'Falta la factura fiscal de esta semana en la carpeta.';
        }

        $xls = null;
        if ($ordersXls) {
            try {
                $xls = $this->orderListReader->read($ordersXls);
            } catch (\Throwable $e) {
                $fileWarnings[] = "No se pudo leer el Excel de pedidos ({$e->getMessage()}): no se controlan las ventas ni la comisión ni los reclamos.";
            }
        } else {
            $fileWarnings[] = 'Falta el Excel de pedidos de esta semana en la carpeta.';
        }

        $safety = $this->validateSafetyControls($statement, $xls, $invoice);
        $controls = $safety['controls'];
        $blockers = $safety['blockers'];
        $warnings = array_merge($fileWarnings, $safety['warnings']);

        if ($status === 'overlaps') {
            $blockers[] = "La semana se cruza con otra ya cargada (" . $this->weekLabel($existingSettlement) . ").";
        } elseif ($status === 'same_dates_diff_amounts') {
            $warnings[] = "Ya existe una liquidación en estas fechas con montos distintos (cargado: Bs {$existingSettlement->total_payout}, nuevo: Bs {$statement['total_payout']}). Elimínala en «Semanas del mes» y vuelve a importar la carpeta para reemplazarla.";
        }

        // CSV de pedidos (uno o varios, por ejemplo el de cada mes): se leen una vez y se juntan por número de
        // pedido; el que no se puede leer se avisa y sus pedidos salen del Excel
        $csvOrders = $this->readCsvFiles($csvFile, $warnings);

        $plan = $this->planOrders($csvOrders, $xls, $statement['start_date'], $statement['end_date']);
        $warnings = array_merge($warnings, $plan['warnings']);

        // Una semana ya importada a la que le faltan pedidos, su detalle o sus ventas se completa con estos archivos
        if ($status === 'already_imported' && $existingSettlement) {
            $toComplete = $this->ordersToComplete($existingSettlement, $plan['orders']);
            $withoutSale = $existingSettlement->orders()->withDetail()->whereNull('sale_id')->get(['status'])
                ->filter(fn ($o) => self::isDelivered($o->status))->count();
            if ($toComplete > 0) {
                $status = 'complete';
                $warnings[] = "Ya estaba importada, pero le faltaban {$toComplete} pedido(s) o su detalle: se completan con estos archivos.";
            } elseif ($withoutSale > 0) {
                $status = 'complete';
                $warnings[] = "Ya estaba importada, pero {$withoutSale} pedido(s) no tienen su venta en el POS: se crean o vinculan al importar (si está marcado «Crear o vincular las ventas»).";
            }
        }

        $sales = null;
        $withDetail = array_values(array_filter($plan['orders'], fn ($o) => ($o['items_text'] ?? null) !== null));
        if ($status === 'complete' && $existingSettlement) {
            // Pedidos con detalle que la semana ya tenía y que este CSV no trae: también entran al análisis de ventas
            $inPlan = array_flip(array_map('strval', array_column($withDetail, 'order_number')));
            foreach ($existingSettlement->orders()->withDetail()->get() as $o) {
                if (!isset($inPlan[(string) $o->order_number])) {
                    $withDetail[] = [
                        'order_number' => $o->order_number, 'status' => $o->status, 'order_date' => $o->order_date->format('Y-m-d H:i:s'),
                        'subtotal' => (float) $o->subtotal, 'items_text' => $o->items_text,
                    ];
                }
            }
        }
        if ($withDetail && in_array($status, ['new', 'complete'], true)) {
            $salesAnalysis = $this->salesLinker->analyze($withDetail, $statement['start_date'], $statement['end_date'], $links);
            $sales = array_diff_key($salesAnalysis, ['payloads' => true]);
        }

        return [
            'statement_number'    => $statementNumber,
            'start_date'          => $statement['start_date'],
            'end_date'            => $statement['end_date'],
            'payout_date'         => $statement['payout_date'],
            'status'              => $status,
            'existing_settlement' => $existingSettlement,
            'statement'           => $statement,
            'invoice'             => $invoice,
            'claims'              => $xls['claims'] ?? [],
            'claims_count'        => $xls['claims_count'] ?? 0,
            'orders_count'        => count($plan['orders']),
            'orders_with_detail'  => count($withDetail),
            'orders_source'       => $plan['source'],
            'controls'            => $controls,
            'warnings'            => $warnings,
            'blockers'            => $blockers,
            'sales'               => $sales,
            'orders_plan'         => $plan['orders'],
        ];
    }

    /**
     * Lee uno o varios CSV de pedidos y junta sus pedidos por número. Null si no hay ninguno legible.
     *
     * @param UploadedFile|string|array<int, UploadedFile|string>|null $csvFiles
     */
    public function readCsvFiles(UploadedFile|string|array|null $csvFiles, array &$warnings): ?array
    {
        $files = array_values(array_filter(is_array($csvFiles) ? $csvFiles : [$csvFiles]));
        if ($files === []) {
            return null;
        }
        $orders = null;
        foreach ($files as $file) {
            $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename((string) $file);
            try {
                foreach ($this->parseCsv($file)['orders'] as $o) {
                    $orders[(string) $o['order_number']] = $o;
                }
            } catch (\Throwable $e) {
                $warnings[] = "No se pudo leer el CSV de pedidos «{$name}» ({$e->getMessage()}): sus pedidos salen del Excel, sin artículos, y no se crean sus ventas.";
            }
        }

        return $orders === null ? null : array_values($orders);
    }

    /**
     * Pedidos de la semana: los del CSV que caen en la semana (con artículos y hora) y, de los que liquidó
     * PedidosYa según el Excel, los que el CSV no trae (sin artículos: `items_text` nulo, a las 12:00).
     *
     * @return array{orders: array<int, array>, source: ?string, warnings: array<int, string>}
     */
    public function planOrders(?array $csvOrders, ?array $xls, string $startDate, string $endDate): array
    {
        $warnings = [];
        $orders = [];

        if ($csvOrders !== null) {
            $inWeek = array_values(array_filter($csvOrders, function ($o) use ($startDate, $endDate) {
                $day = substr((string) $o['order_date'], 0, 10);
                return $day >= $startDate && $day <= $endDate;
            }));
            foreach ($inWeek as $o) {
                $orders[(string) $o['order_number']] = $o;
            }
            if ($inWeek === [] && $csvOrders !== []) {
                $warnings[] = 'El CSV no trae pedidos de esta semana: se usan los del Excel, sin artículos, y no se crean ventas.';
            }
        }

        $missing = 0;
        $missingDays = [];
        foreach ($xls['orders'] ?? [] as $o) {
            $number = (string) $o['order_number'];
            if (isset($orders[$number])) {
                continue;
            }
            $missing++;
            if ($o['order_date']) {
                $missingDays[] = $o['order_date'];
            }
            $orders[$number] = [
                'order_number'        => $number,
                'status'              => $o['status'] ?: 'Entregado',
                'order_date'          => ($o['order_date'] ?: $startDate) . ' 12:00:00',
                'accepted_at'         => null,
                'ready_at'            => null,
                'pickup_at'           => null,
                'delivered_at'        => null,
                'cancelled_at'        => null,
                'payment_method'      => $o['payment_method'] ?? null,
                'delivery_type'       => $o['delivery_method'] ?? null,
                'subtotal'            => (float) $o['gross_amount'],
                'commission'          => (float) ($o['commission_amount'] ?? 0),
                'tax'                 => 0.0,
                'vendor_discount'     => 0.0,
                'pedidosya_discount'  => round((float) $o['gross_amount'] - (float) $o['net_amount'], 2),
                'estimated_income'    => (float) $o['net_amount'],
                'items_text'          => null,
                'cancellation_reason' => null,
                'raw_data'            => array_merge($o, ['source' => 'xls']),
            ];
        }

        if ($csvOrders !== null && $missing > 0 && count($orders) > $missing) {
            sort($missingDays);
            $range = $missingDays
                ? ' (del ' . Carbon::parse($missingDays[0])->format('d/m') . ' al ' . Carbon::parse(end($missingDays))->format('d/m') . ')'
                : '';
            $warnings[] = "{$missing} pedido(s) del Excel{$range} no están en el CSV: se guardan sin artículos y no se crea su venta. Si son de otro mes, pon también el CSV de ese mes en la carpeta.";
        }

        $source = null;
        if ($orders !== []) {
            $source = $missing === 0 ? 'csv' : ($missing === count($orders) ? 'xls' : 'mixed');
        }

        uasort($orders, fn ($a, $b) => strcmp((string) $a['order_date'], (string) $b['order_date']));

        return ['orders' => array_values($orders), 'source' => $source, 'warnings' => $warnings];
    }

    /**
     * Pedidos del plan que completan una semana ya importada: los que no tiene (por ejemplo, una semana guardada sin
     * ningún pedido) y los que tiene sin artículos cuando ahora llegan del CSV.
     */
    protected function ordersToComplete(PedidosYaSettlement $settlement, array $plan): int
    {
        if ($plan === []) {
            return 0;
        }
        $existing = $settlement->orders()->get(['order_number', 'items_text'])->keyBy('order_number');

        return count(array_filter($plan, function ($o) use ($existing) {
            $row = $existing->get((string) $o['order_number']);
            if (!$row) {
                return true;
            }
            return ($o['items_text'] ?? null) !== null && !PedidosYaOrder::hasDetail($row->items_text);
        }));
    }

    /**
     * Guarda los pedidos del plan en la semana. Un pedido que ya existe conserva su venta vinculada; uno que
     * pertenece a otra semana vigente no se mueve (se avisa). Un pedido del Excel no pisa el detalle que ya
     * tenía del CSV.
     *
     * @return array{delivered: int, cancelled: int, warnings: array<int, string>}
     */
    protected function saveWeekOrders(PedidosYaSettlement $settlement, array $orders): array
    {
        $delivered = 0;
        $cancelled = 0;
        $warnings = [];
        $existing = PedidosYaOrder::with('settlement')->whereIn('order_number', array_column($orders, 'order_number'))->get()->keyBy('order_number');

        foreach ($orders as $o) {
            $number = (string) $o['order_number'];
            $row = $existing->get($number);

            if ($row && $row->settlement && $row->settlement_id !== $settlement->id) {
                $warnings[] = "El pedido {$number} ya pertenece a la semana {$this->weekLabel($row->settlement)}: no se movió.";
                continue;
            }

            if (self::isDelivered($o['status'] ?? null)) {
                $delivered++;
            } elseif (mb_strtolower(trim((string) ($o['status'] ?? ''))) === 'cancelado') {
                $cancelled++;
            }

            if ($row && $o['items_text'] === null && PedidosYaOrder::hasDetail($row->items_text)) {
                // Ya tenía el detalle del CSV: solo cambia de semana
                $row->update(['settlement_id' => $settlement->id]);
                continue;
            }

            $data = array_merge($o, ['settlement_id' => $settlement->id]);
            if ($row) {
                $row->update($data);
            } else {
                PedidosYaOrder::create($data);
            }
        }

        return ['delivered' => $delivered, 'cancelled' => $cancelled, 'warnings' => $warnings];
    }

    /** Totales de la semana calculados de sus pedidos guardados. */
    protected function refreshOrderTotals(PedidosYaSettlement $settlement, ?string $source, int $delivered, int $cancelled): void
    {
        $orders = $settlement->orders()->get();
        $settlement->update([
            'orders_count'           => $orders->count(),
            'delivered_count'        => $delivered,
            'cancelled_count'        => $cancelled,
            'orders_source'          => $source,
            'csv_gross_sales'        => round((float) $orders->filter(fn ($o) => self::isDelivered($o->status))->sum('subtotal'), 2),
            'csv_commission'         => round((float) $orders->sum('commission'), 2),
            'csv_estimated_income'   => round((float) $orders->sum('estimated_income'), 2),
            'csv_pedidosya_discount' => round((float) $orders->sum('pedidosya_discount'), 2),
            'csv_vendor_discount'    => round((float) $orders->sum('vendor_discount'), 2),
        ]);
    }

    /**
     * Una semana borrada (soft delete) que todavía guarda su número de liquidación lo libera, para que la misma
     * semana se pueda volver a importar.
     */
    public function releaseDeletedStatementNumber(?string $statementNumber): void
    {
        if (!$statementNumber) {
            return;
        }
        PedidosYaSettlement::onlyTrashed()->where('statement_number', $statementNumber)->get()->each(function (PedidosYaSettlement $st) {
            $st->forceFill([
                'statement_number' => null,
                'notes'            => trim(($st->notes ? $st->notes . ' ' : '') . "Liquidación N.º {$st->getOriginal('statement_number')} (eliminada)."),
            ])->saveQuietly();
        });
    }

    /**
     * Importa una semana directamente desde los archivos de la carpeta del paquete semanal.
     *
     * - Nueva: crea la semana, sus pedidos (CSV y, si faltan, Excel) y, con `create`, sus ventas.
     * - Ya importada: no cambia nada (`skipped`), salvo que el CSV traiga el detalle que le faltaba (`complete`).
     * - Cargada a mano con los mismos montos: se le agregan número, fecha de pago, factura, reclamos y archivos.
     * - Montos distintos o cruzada con otra: se rechaza.
     * Un error real revierte la semana completa (y borra los archivos que guardó).
     */
    public function importSettlementFromFolder(
        UploadedFile|string $statementFile,
        UploadedFile|string|null $ordersXls = null,
        UploadedFile|string|null $invoicePdf = null,
        UploadedFile|string|array|null $csvFile = null,
        ?string $statementFilename = null,
        ?string $statementNumber = null,
        string $salesMode = 'skip',
        array $links = [],
        ?string $notes = null,
        ?int $userId = null
    ): array {
        $read = $this->readWeek($statementFile, $ordersXls, $invoicePdf, $csvFile, $statementFilename, $statementNumber, $links);

        if (count($read['blockers']) > 0) {
            throw ValidationException::withMessages([
                'statement_file' => implode(' ', $read['blockers']),
            ]);
        }

        $warnings = $read['warnings'];

        if ($read['status'] === 'already_imported') {
            return [
                'settlement' => $read['existing_settlement'],
                'warnings'   => array_merge($warnings, ['Esta semana ya estaba importada: no se cambió nada.']),
                'sales'      => null,
                'skipped'    => true,
            ];
        }

        if ($read['status'] === 'same_dates_diff_amounts') {
            throw ValidationException::withMessages([
                'statement_file' => 'Ya existe una liquidación para este período con montos distintos. Elimínala en «Semanas del mes» y vuelve a importar la carpeta para reemplazarla.',
            ]);
        }

        $stNum = $read['statement_number'] ?: 'settlement_' . str_replace('-', '', $read['start_date']);
        $folder = "pedidosya/{$stNum}";
        $files = [];
        $salesResult = null;

        try {
            if ($statementFile) {
                $files['statement'] = Storage::disk('local')->putFileAs($folder, $statementFile, 'statement.pdf');
            }
            if ($ordersXls) {
                $files['orders'] = Storage::disk('local')->putFileAs($folder, $ordersXls, 'orders.xls');
            }
            if ($invoicePdf) {
                $files['invoice'] = Storage::disk('local')->putFileAs($folder, $invoicePdf, 'invoice.pdf');
            }

            $settlement = DB::transaction(function () use ($read, $files, $notes, $userId, $salesMode, $links, &$warnings, &$salesResult) {
                $inv = $read['invoice'];

                if ($read['status'] === 'same_dates_same_amounts' || $read['status'] === 'complete') {
                    $settlement = $read['existing_settlement'];
                    $settlement->update([
                        'statement_number'      => $read['statement_number'] ?: $settlement->statement_number,
                        'payout_date'           => $settlement->payout_date ?: $read['payout_date'],
                        'invoice_number'        => $inv['invoice_number'] ?? $settlement->invoice_number,
                        'invoice_date'          => $inv['invoice_date'] ?? $settlement->invoice_date,
                        'invoice_authorization' => $inv['invoice_authorization'] ?? $settlement->invoice_authorization,
                        'invoice_total'         => $inv['invoice_total'] ?? $settlement->invoice_total,
                        'claims'                => $read['claims'] ?: $settlement->claims,
                        'files'                 => array_merge($settlement->files ?? [], $files),
                    ]);

                    if ($read['status'] === 'same_dates_same_amounts') {
                        // Semana cargada a mano: sus pedidos y ventas no se tocan
                        $warnings[] = 'Se vincularon los archivos oficiales a la semana cargada a mano.';
                        return $settlement->fresh(['orders']);
                    }
                } else {
                    $this->releaseDeletedStatementNumber($read['statement_number']);
                    $st = $read['statement'];
                    $settlement = PedidosYaSettlement::create([
                        'statement_number'      => $read['statement_number'],
                        'start_date'            => $read['start_date'],
                        'end_date'              => $read['end_date'],
                        'payout_date'           => $read['payout_date'],
                        'net_sales'             => $st['net_sales'],
                        'delivery_fees'         => $st['delivery_fees'],
                        'service_fees'          => $st['service_fees'],
                        'operating_charges'     => $st['operating_charges'],
                        'advertising'           => $st['advertising'],
                        'refunds'               => $st['refunds'],
                        'taxes'                 => $st['taxes'],
                        'total_payout'          => $st['total_payout'],
                        'calculated_payout'     => round($st['net_sales'] - $st['delivery_fees'] - $st['service_fees'] - $st['operating_charges'] - $st['advertising'] + $st['refunds'] - $st['taxes'], 2),
                        'discrepancy'           => 0.0,
                        'invoice_number'        => $inv['invoice_number'] ?? null,
                        'invoice_date'          => $inv['invoice_date'] ?? null,
                        'invoice_authorization' => $inv['invoice_authorization'] ?? null,
                        'invoice_total'         => $inv['invoice_total'] ?? null,
                        'claims'                => $read['claims'],
                        'files'                 => $files,
                        'notes'                 => $notes,
                        'user_id'               => $userId,
                    ]);
                }

                $saved = $this->saveWeekOrders($settlement, $read['orders_plan']);
                $warnings = array_merge($warnings, $saved['warnings']);
                $this->refreshOrderTotals($settlement, $read['orders_source'], $saved['delivered'], $saved['cancelled']);

                if ($salesMode === 'create' && $settlement->orders()->withDetail()->exists()) {
                    $salesResult = $this->salesLinker->sync($settlement->fresh(), $links, $userId);
                }

                return $settlement->fresh(['orders']);
            });
        } catch (\Throwable $e) {
            // La semana no se guardó: tampoco quedan sus archivos (salvo los de una semana que ya existía)
            if (!in_array($read['status'], ['same_dates_same_amounts', 'complete'], true)) {
                Storage::disk('local')->delete(array_values($files));
            }
            throw $e;
        }

        if ($salesResult && ($salesResult['without_detail'] ?? 0) > 0) {
            $warnings[] = "{$salesResult['without_detail']} pedido(s) sin detalle de artículos: no se creó su venta.";
        }
        if ($salesResult && count($salesResult['errors'] ?? []) > 0) {
            $warnings[] = count($salesResult['errors']) . ' pedido(s) no se pudieron registrar como venta: ' . implode(' ', $salesResult['errors']);
        }

        return [
            'settlement' => $settlement,
            'warnings'   => array_values(array_unique($warnings)),
            'sales'      => $salesResult,
            'completed'  => $read['status'] === 'complete',
        ];
    }
}
