<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderQueueController extends Controller
{
    /**
     * Listado de pedidos activos en la cola de preparación (KDS).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Sale::with([
            'customer:id,name,ci_or_phone',
            'cashier:id,name',
            'items.product:id,name',
            'items.saleItemOptions.option:id,name,additional_price',
            'items.saleItemOptions.optionGroup:id,name',
            'deliveryOrder',
        ])
        ->where('status', 'completed')
        // Mostrar pedidos del día o las últimas 24 horas para no perder pedidos en cambio de medianoche
        ->where('created_at', '>=', now()->subHours(24));

        // Filtro opcional por origen (pos, pedidosya)
        if ($source = $request->source) {
            $query->where('source', $source);
        }

        // Filtro opcional por estado
        if ($status = $request->status) {
            $query->where('preparation_status', $status);
        }

        $orders = $query->orderBy('created_at', 'asc')->get();

        // Agrupar conteos por estado
        $allOrders = $query->get();
        $counts = [
            'received'  => $allOrders->where('preparation_status', 'received')->count(),
            'preparing' => $allOrders->where('preparation_status', 'preparing')->count(),
            'ready'     => $allOrders->where('preparation_status', 'ready')->count(),
            'delivered' => $allOrders->where('preparation_status', 'delivered')->count(),
        ];

        return response()->json([
            'orders' => $orders,
            'counts' => $counts,
        ]);
    }

    /**
     * Avanza o cambia el estado de preparación de un pedido.
     */
    public function updateStatus(Request $request, Sale $sale): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:received,preparing,ready,delivered',
        ]);

        $newStatus = $validated['status'];
        $sale->preparation_status = $newStatus;

        if ($newStatus === 'preparing' && !$sale->preparation_started_at) {
            $sale->preparation_started_at = now();
        } elseif ($newStatus === 'ready' && !$sale->ready_at) {
            $sale->ready_at = now();
        } elseif ($newStatus === 'delivered' && !$sale->delivered_at) {
            $sale->delivered_at = now();
        }

        $sale->save();

        // Sincronizar estado con el delivery_order si existe
        if ($sale->deliveryOrder) {
            $deliveryMap = [
                'received'  => 'received',
                'preparing' => 'preparing',
                'ready'     => 'ready',
                'delivered' => 'picked_up',
            ];
            $sale->deliveryOrder->update([
                'status' => $deliveryMap[$newStatus] ?? 'received',
            ]);
        }

        $sale->load([
            'customer:id,name,ci_or_phone',
            'cashier:id,name',
            'items.product:id,name',
            'items.saleItemOptions.option:id,name,additional_price',
            'deliveryOrder',
        ]);

        return response()->json([
            'message' => 'Estado de pedido actualizado correctamente.',
            'order' => $sale,
        ]);
    }
}
