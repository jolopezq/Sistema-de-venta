<?php

namespace App\Http\Controllers;

use App\Models\PedidosYaSettlement;
use App\Services\PedidosYaImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PedidosYaSettlementController extends Controller
{
    public function __construct(
        protected PedidosYaImportService $importService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PedidosYaSettlement::with('user:id,name');

        if ($request->filled('year') && $request->filled('month')) {
            // Semanas cuya fecha de fin cae en el mes (mismo criterio que el Estado de Resultados)
            $start = sprintf('%04d-%02d-01', (int) $request->year, (int) $request->month);
            $query->whereBetween('end_date', [$start, date('Y-m-t', strtotime($start))]);
        } elseif ($request->filled('year')) {
            $query->whereYear('end_date', $request->year);
        }

        $settlements = $query->orderBy('start_date', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($settlements);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'csv_file'          => ['required', 'file', 'max:10240'],
            'start_date'        => ['required', 'date'],
            'end_date'          => ['required', 'date', 'after_or_equal:start_date'],
            'payout_date'       => ['nullable', 'date'],
            'net_sales'         => ['required', 'numeric', 'min:0'],
            'delivery_fees'     => ['nullable', 'numeric'],
            'service_fees'      => ['required', 'numeric'],
            'operating_charges' => ['nullable', 'numeric'],
            'advertising'       => ['nullable', 'numeric'],
            'refunds'           => ['nullable', 'numeric'],
            'taxes'             => ['nullable', 'numeric'],
            'total_payout'      => ['required', 'numeric'],
            'zip_file'          => ['nullable', 'file', 'max:51200'],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ]);

        $zipPath = null;
        if ($request->hasFile('zip_file')) {
            $zipPath = $request->file('zip_file')->store('pedidosya_invoices');
        }

        $settlement = $this->importService->importSettlement(
            $validated,
            $request->file('csv_file'),
            Auth::id(),
            $zipPath
        );

        return response()->json([
            'message'    => 'Liquidación semanal de PedidosYa importada correctamente.',
            'settlement' => $settlement,
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
