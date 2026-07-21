<?php

namespace App\Http\Controllers;

use App\Services\SaleSyncService;
use App\Http\Requests\SyncSalesRequest;
use Illuminate\Http\JsonResponse;

class SaleController extends Controller
{
    protected SaleSyncService $syncService;

    public function __construct(SaleSyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Sincroniza un lote de ventas recibidas del frontend de manera asíncrona.
     */
    public function sync(SyncSalesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        
        $cashierId = $request->user()->id;

        foreach ($validated['sales'] as $saleData) {
            $saleData['cashier_id'] = $cashierId; // Inyectamos el ID del cajero
            \App\Jobs\ProcessOfflineSaleJob::dispatch($saleData);
        }

        return response()->json([
            'message' => 'Lote de ventas enviado a la cola para procesamiento asíncrono.',
        ], 202);
    }

    /**
     * Anula una venta y revierte el inventario.
     */
    public function voidSale(\App\Models\Sale $sale, \Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'void_reason' => 'required|string|max:255',
        ]);

        try {
            $this->syncService->voidSale($sale, $request->user()->id, $validated['void_reason']);

            return response()->json([
                'message' => 'Venta anulada correctamente.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al anular la venta.',
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
