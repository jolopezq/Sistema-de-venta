<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\SaleSyncService;
use App\Http\Requests\SyncSalesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Http\Requests\AdminSaleRequest;

class SaleController extends Controller
{
    protected SaleSyncService $syncService;

    public function __construct(SaleSyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Listado filtrable de ventas con resumen financiero.
     */
    public function index(Request $request): JsonResponse
    {
        $baseQuery = Sale::query();

        // Filtro por fecha exacta
        if ($date = $request->date) {
            $baseQuery->whereDate('created_at', $date);
        }

        // Filtro por rango de fechas
        if ($from = $request->from) {
            $baseQuery->where('created_at', '>=', $from . ' 00:00:00');
        }

        if ($to = $request->to) {
            $baseQuery->where('created_at', '<=', $to . ' 23:59:59');
        }

        // Filtro por cajero
        if ($cashierId = $request->cashier_id) {
            $baseQuery->where('cashier_id', $cashierId);
        }

        // Filtro por estado
        if ($status = $request->status) {
            $baseQuery->where('status', $status);
        }

        // Filtro por método de pago
        if ($method = $request->payment_method) {
            $baseQuery->whereHas('payments', function ($q) use ($method) {
                $q->where('method', $method);
            });
        }

        // Búsqueda por texto (UUID, cliente, notas)
        if ($search = $request->search) {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhere('order_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%")
                         ->orWhere('ci_or_phone', 'like', "%{$search}%");
                  })
                  ->orWhereHas('items', function ($iq) use ($search) {
                      $iq->whereHas('product', function ($pq) use ($search) {
                          $pq->where('name', 'like', "%{$search}%");
                      });
                  });
            });
        }

        // Clonar para calcular totales agregados del filtro
        $summaryQuery = clone $baseQuery;
        $completedSales = (clone $summaryQuery)->where('status', 'completed')->get();
        $voidedCount = (clone $summaryQuery)->where('status', 'voided')->count();

        $completedIds = $completedSales->pluck('id');

        $paymentTotals = SalePayment::whereIn('sale_id', $completedIds)
            ->select('method', DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->pluck('total', 'method')
            ->toArray();

        $summary = [
            'total_sales' => (float) $completedSales->sum('total_amount'),
            'total_discounts' => (float) $completedSales->sum('discount_amount'),
            'sales_count' => $completedSales->count(),
            'voided_count' => $voidedCount,
            'cash_total' => (float) ($paymentTotals['cash'] ?? 0),
            'qr_total' => (float) ($paymentTotals['qr'] ?? 0),
            'card_total' => (float) ($paymentTotals['card'] ?? 0),
        ];

        // Obtener listado paginado con relaciones
        $sales = $baseQuery->with([
            'cashier:id,name,role',
            'customer:id,name,ci_or_phone',
            'items.product:id,name',
            'items.saleItemOptions.option:id,name,additional_price',
            'payments',
            'voidedByUser:id,name',
            'editedByUser:id,name',
        ])
        ->latest('created_at')
        ->paginate($request->per_page ?? 20);

        return response()->json([
            'sales' => $sales,
            'summary' => $summary,
        ]);
    }

    /**
     * Detalle completo de una venta específica.
     */
    public function show(Sale $sale): JsonResponse
    {
        $sale->load([
            'cashier:id,name,role',
            'customer',
            'items.product',
            'items.saleItemOptions.option',
            'payments',
            'voidedByUser:id,name',
            'editedByUser:id,name',
        ]);

        return response()->json($sale);
    }

    /**
     * Sincroniza un lote de ventas recibidas del frontend (Offline-First).
     */
    public function sync(SyncSalesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $cashierId = $request->user()->id;

        $results = $this->syncService->syncBatch($validated['sales'], $cashierId);

        return response()->json([
            'message' => 'Lote de ventas procesado exitosamente.',
            'data' => $results,
        ], 200);
    }

    /**
     * Registra una venta manual o retroactiva (Solo Super Admin).
     */
    public function adminCreate(AdminSaleRequest $request): JsonResponse
    {
        try {
            $sale = $this->syncService->adminCreateSale(
                $request->validated(),
                $request->user()->id
            );

            return response()->json([
                'message' => 'Venta retroactiva registrada correctamente.',
                'sale'    => $sale,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al registrar la venta retroactiva: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Edita una venta existente corrigiendo items, pagos y stock (Solo Super Admin).
     */
    public function adminUpdate(AdminSaleRequest $request, Sale $sale): JsonResponse
    {
        try {
            $updatedSale = $this->syncService->adminUpdateSale(
                $sale,
                $request->validated(),
                $request->user()->id
            );

            return response()->json([
                'message' => 'Venta actualizada correctamente.',
                'sale'    => $updatedSale,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar la venta: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Anula una venta y revierte el inventario.
     */
    public function voidSale(Sale $sale, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'void_reason' => 'required|string|max:255',
        ]);

        try {
            $this->syncService->voidSale($sale, $request->user()->id, $validated['void_reason']);

            return response()->json([
                'message' => 'Venta anulada correctamente.',
                'sale' => $sale->fresh(['voidedByUser']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al anular la venta.',
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
