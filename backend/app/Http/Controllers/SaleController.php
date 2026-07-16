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
     * Sincroniza un lote de ventas recibidas del frontend.
     */
    public function sync(SyncSalesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        
        // El usuario autenticado (cajero)
        $cashierId = $request->user()->id;

        // Ejecutar el servicio
        $result = $this->syncService->syncBatch($validated['sales'], $cashierId);

        // Si hay fallos parciales, retornamos 207 Multi-Status
        $status = empty($result['failed']) ? 200 : 207;

        return response()->json([
            'message' => 'Proceso de sincronización finalizado.',
            'data' => $result,
        ], $status);
    }
}
