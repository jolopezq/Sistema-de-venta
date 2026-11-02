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
            if (abs($expectedInvoice - $invoice['invoice_total']) > 0.05) {
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
     */
    public function readWeek(
        UploadedFile|string $statementFile,
        UploadedFile|string|null $ordersXls = null,
        UploadedFile|string|null $invoicePdf = null,
        UploadedFile|string|null $csvFile = null,
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

        $invoice = null;
        if ($invoicePdf) {
            try {
                $invoice = $this->invoiceReader->read($invoicePdf);
            } catch (\Throwable $e) {
                // Factura opcional o no parseable
            }
        }

        $xls = null;
        if ($ordersXls) {
            try {
                $xls = $this->orderListReader->read($ordersXls);
            } catch (\Throwable $e) {
                // Excel no parseable
            }
        }

        $safety = $this->validateSafetyControls($statement, $xls, $invoice);
        $controls = $safety['controls'];
        $blockers = $safety['blockers'];
        $warnings = $safety['warnings'];

        if ($status === 'overlaps') {
            $blockers[] = "La semana se cruza con otra ya cargada (" . $this->weekLabel($existingSettlement) . ").";
        } elseif ($status === 'same_dates_diff_amounts') {
            $warnings[] = "Ya existe una liquidación en estas fechas con montos distintos (cargado: Bs {$existingSettlement->total_payout}, nuevo: Bs {$statement['total_payout']}).";
        }

        $ordersCount = 0;
        $ordersSource = null;
        $sales = null;

        if ($csvFile) {
            try {
                $csv = $this->parseCsv($csvFile);
                $weekOrders = array_values(array_filter($csv['orders'], function ($o) use ($statement) {
                    $day = substr((string) $o['order_date'], 0, 10);
                    return $day >= $statement['start_date'] && $day <= $statement['end_date'];
                }));
                $outsideCount = count($csv['orders']) - count($weekOrders);
                if ($outsideCount > 0) {
                    $warnings[] = "El CSV contiene {$outsideCount} pedido(s) fuera de la semana ({$statement['start_date']} al {$statement['end_date']}).";
                }
                $ordersCount = count($weekOrders);
                $ordersSource = 'csv';
                $salesAnalysis = $this->salesLinker->analyze($weekOrders, $statement['start_date'], $statement['end_date'], $links);
                $sales = array_diff_key($salesAnalysis, ['payloads' => true]);
            } catch (\Throwable $e) {
                $warnings[] = "No se pudo procesar el CSV de pedidos: " . $e->getMessage();
            }
        } elseif ($xls) {
            $ordersCount = $xls['orders_count'];
            $ordersSource = 'xls';
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
            'orders_count'        => $ordersCount,
            'orders_source'       => $ordersSource,
            'controls'            => $controls,
            'warnings'            => $warnings,
            'blockers'            => $blockers,
            'sales'               => $sales,
        ];
    }

    /**
     * Importa una semana directamente desde los archivos de la carpeta del paquete semanal.
     */
    public function importSettlementFromFolder(
        UploadedFile|string $statementFile,
        UploadedFile|string|null $ordersXls = null,
        UploadedFile|string|null $invoicePdf = null,
        UploadedFile|string|null $csvFile = null,
        ?string $statementFilename = null,
        ?string $statementNumber = null,
        string $salesMode = 'skip',
        array $links = [],
        ?string $notes = null,
        ?int $userId = null
    ): array {
        $read = $this->readWeek(
            $statementFile,
            $ordersXls,
            $invoicePdf,
            $csvFile,
            $statementFilename,
            $statementNumber,
            $links
        );

        if (count($read['blockers']) > 0) {
            throw ValidationException::withMessages([
                'statement_file' => implode(' ', $read['blockers']),
            ]);
        }

        if ($read['status'] === 'already_imported') {
            return [
                'settlement' => $read['existing_settlement'],
                'warnings'   => array_merge($read['warnings'], ['Esta semana ya había sido importada previamente.']),
                'sales'      => null,
                'skipped'    => true,
            ];
        }

        if ($read['status'] === 'same_dates_diff_amounts') {
            throw ValidationException::withMessages([
                'statement_file' => 'Ya existe una liquidación para este período pero con montos distintos. Elimínala manualmente si deseas reemplazarla.',
            ]);
        }

        $stNum = $read['statement_number'] ?: 'settlement_' . str_replace('-', '', $read['start_date']);
        $folder = "pedidosya/{$stNum}";
        $files = [];

        if ($statementFile) {
            $files['statement'] = Storage::disk('local')->putFileAs($folder, $statementFile, 'statement.pdf');
        }
        if ($ordersXls) {
            $files['orders'] = Storage::disk('local')->putFileAs($folder, $ordersXls, 'orders.xls');
        }
        if ($invoicePdf) {
            $files['invoice'] = Storage::disk('local')->putFileAs($folder, $invoicePdf, 'invoice.pdf');
        }

        if ($read['status'] === 'same_dates_same_amounts') {
            $existing = $read['existing_settlement'];
            $existing->update([
                'statement_number'      => $read['statement_number'],
                'invoice_number'        => $read['invoice']['invoice_number'] ?? $existing->invoice_number,
                'invoice_date'          => $read['invoice']['invoice_date'] ?? $existing->invoice_date,
                'invoice_authorization' => $read['invoice']['invoice_authorization'] ?? $existing->invoice_authorization,
                'invoice_total'         => $read['invoice']['invoice_total'] ?? $existing->invoice_total,
                'claims'                => $read['claims'] ?: $existing->claims,
                'files'                 => array_merge($existing->files ?? [], $files),
            ]);

            return [
                'settlement' => $existing->fresh(['orders']),
                'warnings'   => array_merge($read['warnings'], ['Se vincularon los archivos oficiales a la liquidación existente cargada manualmente.']),
                'sales'      => null,
            ];
        }

        $st = $read['statement'];
        $inv = $read['invoice'];
        $salesResult = null;

        $settlement = DB::transaction(function () use (
            $read,
            $st,
            $inv,
            $files,
            $notes,
            $userId,
            $csvFile,
            $ordersXls,
            $salesMode,
            $links,
            &$salesResult
        ) {
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
                'orders_count'          => $read['orders_count'],
                'orders_source'         => $read['orders_source'],
                'invoice_number'        => $inv['invoice_number'] ?? null,
                'invoice_date'          => $inv['invoice_date'] ?? null,
                'invoice_authorization' => $inv['invoice_authorization'] ?? null,
                'invoice_total'         => $inv['invoice_total'] ?? null,
                'claims'                => $read['claims'],
                'files'                 => $files,
                'notes'                 => $notes,
                'user_id'               => $userId,
            ]);

            if ($csvFile) {
                $csv = $this->parseCsv($csvFile);
                $weekOrders = array_values(array_filter($csv['orders'], function ($o) use ($read) {
                    $day = substr((string) $o['order_date'], 0, 10);
                    return $day >= $read['start_date'] && $day <= $read['end_date'];
                }));

                $delivered = 0;
                $cancelled = 0;
                foreach ($weekOrders as $o) {
                    if (self::isDelivered($o['status'] ?? null)) {
                        $delivered++;
                    } elseif (mb_strtolower((string) ($o['status'] ?? '')) === 'cancelado') {
                        $cancelled++;
                    }
                    PedidosYaOrder::updateOrCreate(
                        ['order_number' => $o['order_number']],
                        array_merge($o, ['settlement_id' => $settlement->id])
                    );
                }

                $settlement->update([
                    'delivered_count'        => $delivered,
                    'cancelled_count'        => $cancelled,
                    'csv_gross_sales'        => round(array_sum(array_column($weekOrders, 'subtotal')), 2),
                    'csv_commission'         => round(array_sum(array_column($weekOrders, 'commission')), 2),
                    'csv_estimated_income'   => round(array_sum(array_column($weekOrders, 'estimated_income')), 2),
                    'csv_pedidosya_discount' => round(array_sum(array_column($weekOrders, 'pedidosya_discount')), 2),
                    'csv_vendor_discount'    => round(array_sum(array_column($weekOrders, 'vendor_discount')), 2),
                ]);

                if ($salesMode === 'create') {
                    $salesResult = $this->salesLinker->sync($settlement, $links, $userId);
                }
            } elseif ($ordersXls) {
                $xls = $this->orderListReader->read($ordersXls);
                $delivered = 0;
                $cancelled = 0;
                foreach ($xls['orders'] as $o) {
                    if (mb_strtolower((string) $o['status']) === 'entregado') {
                        $delivered++;
                    } elseif (mb_strtolower((string) $o['status']) === 'cancelado') {
                        $cancelled++;
                    }
                    PedidosYaOrder::updateOrCreate(
                        ['order_number' => $o['order_number']],
                        [
                            'settlement_id'      => $settlement->id,
                            'status'             => $o['status'] ?: 'Entregado',
                            'order_date'         => $o['order_date'] ? $o['order_date'] . ' 12:00:00' : now(),
                            'payment_method'     => $o['payment_method'] ?? 'Online',
                            'delivery_type'      => $o['delivery_method'] ?? 'PedidosYa',
                            'subtotal'           => $o['gross_amount'],
                            'commission'         => $o['commission_amount'],
                            'tax'                => 0.0,
                            'vendor_discount'    => 0.0,
                            'pedidosya_discount' => 0.0,
                            'estimated_income'   => $o['net_amount'],
                            'items_text'         => 'Pedido PedidosYa #' . $o['order_number'],
                            'raw_data'           => $o,
                        ]
                    );
                }
                $settlement->update([
                    'delivered_count' => $delivered,
                    'cancelled_count' => $cancelled,
                ]);
            }

            return $settlement->fresh(['orders']);
        });

        if ($salesResult && count($salesResult['errors'] ?? []) > 0) {
            $read['warnings'][] = count($salesResult['errors']) . ' pedido(s) no se pudieron registrar como venta: ' . implode(' ', $salesResult['errors']);
        }

        return [
            'settlement' => $settlement,
            'warnings'   => $read['warnings'],
            'sales'      => $salesResult,
        ];
    }
}
