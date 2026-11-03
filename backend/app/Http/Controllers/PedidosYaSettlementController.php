<?php

namespace App\Http\Controllers;

use App\Models\PedidosYaSettlement;
use App\Services\PedidosYaImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\PedidosYa\SalesLinker;
use App\Services\PedidosYa\WeeklyReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class PedidosYaSettlementController extends Controller
{
    public function __construct(
        protected PedidosYaImportService $importService,
        protected SalesLinker $linker,
        protected WeeklyReport $reportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PedidosYaSettlement::with('user:id,name');

        if ($request->filled('year') && $request->filled('month')) {
            // Semanas que tocan el mes (las que cruzan dos meses se prorratean en el Estado de Resultados)
            $start = sprintf('%04d-%02d-01', (int) $request->year, (int) $request->month);
            $query->where('start_date', '<=', date('Y-m-t', strtotime($start)) . ' 23:59:59')
                  ->where('end_date', '>=', $start);
        } elseif ($request->filled('year')) {
            $query->whereYear('end_date', $request->year);
        }

        $settlements = $query->orderBy('start_date', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($settlements);
    }

    /**
     * Lee el CSV sin guardar: semana sugerida (lunes a domingo), totales y semanas con las que se cruza.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file'   => ['required', 'file', 'max:10240'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        try {
            $preview = $this->importService->preview(
                $request->file('csv_file'),
                $request->input('start_date'),
                $request->input('end_date')
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['csv_file' => [$e->getMessage()]]], 422);
        }

        return response()->json($preview);
    }

    /**
     * Lee e interpreta los archivos de una semana sin guardarlos (previsualización del paquete).
     */
    public function readWeek(Request $request): JsonResponse
    {
        $request->validate([
            'statement_file'   => ['required', 'file', 'max:10240'],
            'orders_xls'       => ['nullable', 'file', 'max:20480'],
            'invoice_pdf'      => ['nullable', 'file', 'max:10240'],
            'orders_csv'       => ['nullable', 'file', 'max:10240'],
            'csv_file'         => ['nullable', 'file', 'max:10240'],
            'csv_files'        => ['nullable', 'array', 'max:12'],
            'csv_files.*'      => ['file', 'max:10240'],
            'statement_number' => ['nullable', 'regex:/^\d{5,20}$/'],
            'links'            => ['nullable', 'array'],
        ]);

        $statementFile = $request->file('statement_file');
        $ordersXls     = $request->file('orders_xls');
        $invoicePdf    = $request->file('invoice_pdf');
        $csvFile       = $this->csvFiles($request);

        $result = $this->importService->readWeek(
            $statementFile,
            $ordersXls,
            $invoicePdf,
            $csvFile,
            $statementFile->getClientOriginalName(),
            $request->input('statement_number'),
            (array) $request->input('links', [])
        );

        // El plan de pedidos (con cada fila del CSV) es para guardar, no para la pantalla
        return response()->json(array_diff_key($result, ['orders_plan' => true]));
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->input('mode') === 'folder') {
            $request->validate([
                'statement_file'   => ['required', 'file', 'max:10240'],
                'orders_xls'       => ['nullable', 'file', 'max:20480'],
                'invoice_pdf'      => ['nullable', 'file', 'max:10240'],
                'orders_csv'       => ['nullable', 'file', 'max:10240'],
                'csv_file'         => ['nullable', 'file', 'max:10240'],
                'csv_files'        => ['nullable', 'array', 'max:12'],
                'csv_files.*'      => ['file', 'max:10240'],
                'statement_number' => ['nullable', 'regex:/^\d{5,20}$/'],
                'sales_mode'       => ['nullable', 'in:create,skip'],
                'links'            => ['nullable', 'array'],
                'notes'            => ['nullable', 'string', 'max:1000'],
            ]);

            $statementFile = $request->file('statement_file');
            $ordersXls     = $request->file('orders_xls');
            $invoicePdf    = $request->file('invoice_pdf');
            $csvFile       = $this->csvFiles($request);

            try {
                $result = $this->importService->importSettlementFromFolder(
                    $statementFile,
                    $ordersXls,
                    $invoicePdf,
                    $csvFile,
                    $statementFile->getClientOriginalName(),
                    $request->input('statement_number'),
                    $request->input('sales_mode', 'skip'),
                    (array) $request->input('links', []),
                    $request->input('notes'),
                    Auth::id()
                );

                $skipped = (bool) ($result['skipped'] ?? false);
                $completed = (bool) ($result['completed'] ?? false);

                return response()->json([
                    'message'    => $skipped
                        ? 'Esta semana ya estaba importada: no se cambió nada.'
                        : ($completed ? 'Semana de PedidosYa completada.' : 'Semana de PedidosYa importada correctamente.'),
                    'skipped'    => $skipped,
                    'completed'  => $completed,
                    'settlement' => $result['settlement'],
                    'warnings'   => $result['warnings'],
                    'sales'      => $result['sales'],
                ], $skipped ? 200 : 201);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $validated = $request->validate([
            'csv_file'          => ['required', 'file', 'max:10240'],
            // Sin fechas, la semana se toma del CSV (lunes a domingo de sus pedidos)
            'start_date'        => ['nullable', 'date', 'required_with:end_date'],
            'end_date'          => ['nullable', 'date', 'required_with:start_date', 'after_or_equal:start_date'],
            'payout_date'       => ['nullable', 'date'],
            'net_sales'         => ['required', 'numeric', 'min:0'],
            'delivery_fees'     => ['nullable', 'numeric', 'min:0'],
            'service_fees'      => ['required', 'numeric', 'min:0'],
            'operating_charges' => ['nullable', 'numeric', 'min:0'],
            'advertising'       => ['nullable', 'numeric', 'min:0'],
            'refunds'           => ['nullable', 'numeric', 'min:0'],
            'taxes'             => ['nullable', 'numeric', 'min:0'],
            'total_payout'      => ['required', 'numeric'],
            'zip_file'          => ['nullable', 'file', 'max:51200'],
            'notes'             => ['nullable', 'string', 'max:1000'],
            // create: crea o vincula las ventas de los pedidos (por defecto); skip: solo los montos
            'sales_mode'        => ['nullable', 'in:create,skip'],
            'links'             => ['nullable', 'array'],
            'links.*'           => ['nullable', 'string', 'max:64'],
        ]);

        try {
            // Se valida el archivo antes de guardar el ZIP
            $this->importService->parseCsv($request->file('csv_file'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['csv_file' => [$e->getMessage()]]], 422);
        }

        $zipPath = null;
        if ($request->hasFile('zip_file')) {
            $zipPath = $request->file('zip_file')->store('pedidosya_invoices');
        }

        try {
            $result = $this->importService->importSettlement(
                $validated,
                $request->file('csv_file'),
                Auth::id(),
                $zipPath,
                $validated['sales_mode'] ?? 'create',
                $validated['links'] ?? []
            );
        } catch (\InvalidArgumentException $e) {
            if ($zipPath) {
                Storage::delete($zipPath);
            }
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // Si la importación no se guardó, el ZIP subido no queda huérfano
            if ($zipPath) {
                Storage::delete($zipPath);
            }
            throw $e;
        }

        $settlement = $result['settlement'];
        $reconciled = $settlement->reconciliation['status'] === 'ok';
        $message = $reconciled
            ? 'Semana de PedidosYa importada y conciliada.'
            : 'Semana de PedidosYa importada con diferencias de conciliación: revisa el detalle.';
        if ($result['sales']) {
            $message .= " Ventas: {$result['sales']['created']} creadas, {$result['sales']['linked']} vinculadas.";
        }

        return response()->json([
            'message'    => $message,
            'settlement' => $settlement,
            'warnings'   => $result['warnings'],
            'sales'      => $result['sales'],
        ], 201);
    }

    /**
     * Ventas que se crearían o vincularían para los pedidos guardados de la semana.
     */
    public function salesPreview(PedidosYaSettlement $settlement, Request $request): JsonResponse
    {
        // Solo los pedidos con artículos (los que vinieron del Excel no pueden crear venta)
        $orders = $settlement->orders()->withDetail()->get()->map(fn ($o) => [
            'order_number' => $o->order_number,
            'status'       => $o->status,
            'order_date'   => $o->order_date->format('Y-m-d H:i:s'),
            'subtotal'     => (float) $o->subtotal,
            'items_text'   => $o->items_text,
        ])->all();

        $analysis = $this->linker->analyze(
            $orders,
            $settlement->start_date->toDateString(),
            $settlement->end_date->toDateString(),
            (array) $request->input('links', [])
        );

        return response()->json(array_diff_key($analysis, ['payloads' => true]));
    }

    /**
     * Crea o vincula las ventas pendientes de la semana (por ejemplo, después de importar solo los montos).
     */
    public function syncSales(PedidosYaSettlement $settlement, Request $request): JsonResponse
    {
        $request->validate([
            'links'   => ['nullable', 'array'],
            'links.*' => ['nullable', 'string', 'max:64'],
        ]);

        $result = DB::transaction(fn () => $this->linker->sync($settlement, (array) $request->input('links', []), Auth::id()));

        return response()->json([
            'message' => "Ventas: {$result['created']} creadas, {$result['linked']} vinculadas, {$result['already']} ya registradas."
                . (count($result['errors']) ? ' ' . count($result['errors']) . ' con error.' : ''),
            'sales'   => $result,
        ]);
    }

    /**
     * Informe de la semana (plan §6.6).
     */
    public function report(PedidosYaSettlement $settlement): JsonResponse
    {
        return response()->json($this->reportService->build($settlement));
    }

    public function show(PedidosYaSettlement $settlement, Request $request): JsonResponse
    {
        $ordersQuery = $settlement->orders()->with('sale:id,order_number,status');

        if ($request->filled('status')) {
            $ordersQuery->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $ordersQuery->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                  ->orWhere('items_text', 'like', "%{$s}%");
            });
        }

        $orders = $ordersQuery->orderBy('order_date', 'asc')->paginate(50);

        return response()->json([
            'settlement' => $settlement->load('user:id,name'),
            'orders'     => $orders,
        ]);
    }

    public function destroy(PedidosYaSettlement $settlement): JsonResponse
    {
        // Libera el número de liquidación: la misma semana se puede volver a importar
        if ($settlement->statement_number) {
            $settlement->forceFill([
                'notes'            => trim(($settlement->notes ? $settlement->notes . ' ' : '') . "Liquidación N.º {$settlement->statement_number} (eliminada)."),
                'statement_number' => null,
            ])->saveQuietly();
        }
        $settlement->delete();

        return response()->json([
            'message' => 'Liquidación de PedidosYa eliminada correctamente.',
        ]);
    }

    /**
     * Descarga un archivo asociado a la liquidación (statement, orders o invoice).
     */
    public function downloadFile(PedidosYaSettlement $settlement, string $type)
    {
        $allowed = ['statement', 'orders', 'invoice'];
        if (!in_array($type, $allowed, true)) {
            abort(404, 'Tipo de archivo no válido.');
        }

        $files = $settlement->files ?? [];
        $path = $files[$type] ?? null;

        if (!$path || !Storage::disk('local')->exists($path)) {
            abort(404, 'El archivo solicitado no se encuentra en el servidor.');
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $stNum = $settlement->statement_number ?: $settlement->id;
        $downloadFilename = "pedidosya_{$stNum}_{$type}.{$ext}";

        return Storage::disk('local')->download($path, $downloadFilename);
    }

    /**
     * Resumen mensual de facturas emitidas por PedidosYa para crédito fiscal (D8).
     */
    public function invoicesSummary(Request $request): JsonResponse
    {
        $query = PedidosYaSettlement::whereNotNull('invoice_number');

        if ($request->filled('year') && $request->filled('month')) {
            $start = sprintf('%04d-%02d-01', (int) $request->year, (int) $request->month);
            $end = date('Y-m-t', strtotime($start));
            $query->whereBetween('invoice_date', [$start, $end]);
        } elseif ($request->filled('year')) {
            $query->whereYear('invoice_date', $request->year);
        }

        $invoices = $query->orderBy('invoice_date', 'asc')->get();
        $totalAmount = round((float) $invoices->sum('invoice_total'), 2);
        $totalTaxCredit = round($totalAmount * 0.13, 2);

        return response()->json([
            'invoices' => $invoices,
            'summary'  => [
                'count'            => $invoices->count(),
                'total_amount'     => $totalAmount,
                'total_tax_credit' => $totalTaxCredit,
            ],
        ]);
    }

    /** CSV de pedidos de la carpeta: uno (`csv_file` u `orders_csv`) o varios (`csv_files[]`). */
    private function csvFiles(Request $request): array
    {
        return array_values(array_filter(array_merge(
            [$request->file('orders_csv'), $request->file('csv_file')],
            (array) $request->file('csv_files', [])
        )));
    }
}
