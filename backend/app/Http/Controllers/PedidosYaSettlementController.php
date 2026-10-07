<?php

namespace App\Http\Controllers;

use App\Models\PedidosYaSettlement;
use App\Services\PedidosYaImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class PedidosYaSettlementController extends Controller
{
    public function __construct(
        protected PedidosYaImportService $importService
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

    public function store(Request $request): JsonResponse
    {
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

        $result = $this->importService->importSettlement(
            $validated,
            $request->file('csv_file'),
            Auth::id(),
            $zipPath
        );

        $settlement = $result['settlement'];
        $reconciled = $settlement->reconciliation['status'] === 'ok';

        return response()->json([
            'message'    => $reconciled
                ? 'Semana de PedidosYa importada y conciliada.'
                : 'Semana de PedidosYa importada con diferencias de conciliación: revisa el detalle.',
            'settlement' => $settlement,
            'warnings'   => $result['warnings'],
        ], 201);
    }

    public function show(PedidosYaSettlement $settlement, Request $request): JsonResponse
    {
        $ordersQuery = $settlement->orders();

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
        $settlement->delete();

        return response()->json([
            'message' => 'Liquidación de PedidosYa eliminada correctamente.',
        ]);
    }
}
