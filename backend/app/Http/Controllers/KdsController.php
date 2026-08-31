<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controlador KDS (Kitchen Display System) para la aplicación de cocina / comandas.
 * Optimizado para pantallas táctiles y dispositivos móviles Android.
 */
class KdsController extends Controller
{
    /**
     * Listado completo de comandas activas con información detallada de preparación.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Sale::with([
            'customer:id,name,ci_or_phone',
            'cashier:id,name',
            'items.product:id,name,image_url,is_weight_based',
            'items.saleItemOptions.option:id,name,additional_price',
            'items.saleItemOptions.optionGroup:id,name',
            'deliveryOrder',
        ])
        ->where('status', 'completed')
        ->where('created_at', '>=', now()->subHours(24));

        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }

        if ($status = $request->input('status')) {
            $query->where('preparation_status', $status);
        }

        $sales = $query->orderBy('created_at', 'asc')->get();

        // Enriquecer cada pedido con cálculos de tiempo para KDS
        $formattedOrders = $sales->map(function (Sale $sale) {
            return $this->formatKdsOrder($sale);
        });

        // Conteo consolidado por columna KDS
        $allOrders = Sale::where('status', 'completed')
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        $counts = [
            'received'  => $allOrders->where('preparation_status', 'received')->count(),
            'preparing' => $allOrders->where('preparation_status', 'preparing')->count(),
            'ready'     => $allOrders->where('preparation_status', 'ready')->count(),
            'delivered' => $allOrders->where('preparation_status', 'delivered')->count(),
            'total'     => $allOrders->count(),
        ];

        return response()->json([
            'success' => true,
            'orders'  => $formattedOrders,
            'counts'  => $counts,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Actualiza el estado de preparación de un pedido en la cocina.
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

        // Sincronizar estado con PedidosYa si proviene de delivery
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
            'items.product:id,name,image_url,is_weight_based',
            'items.saleItemOptions.option:id,name,additional_price',
            'items.saleItemOptions.optionGroup:id,name',
            'deliveryOrder',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estado de pedido actualizado correctamente.',
            'order'   => $this->formatKdsOrder($sale),
        ]);
    }

    /**
     * Métricas y estadísticas de desempeño de cocina en tiempo real.
     */
    public function stats(): JsonResponse
    {
        $todayOrders = Sale::where('status', 'completed')
            ->where('created_at', '>=', now()->startOfDay())
            ->get();

        // Tiempo promedio de preparación en minutos (de preparing a ready)
        $prepTimes = $todayOrders->filter(function ($s) {
            return $s->preparation_started_at && $s->ready_at;
        })->map(function ($s) {
            return $s->preparation_started_at->diffInMinutes($s->ready_at);
        });

        $avgPrepTime = $prepTimes->count() > 0 ? round($prepTimes->average(), 1) : 0;

        return response()->json([
            'success' => true,
            'stats' => [
                'active_orders'       => $todayOrders->whereIn('preparation_status', ['received', 'preparing'])->count(),
                'ready_orders'        => $todayOrders->where('preparation_status', 'ready')->count(),
                'delivered_today'     => $todayOrders->where('preparation_status', 'delivered')->count(),
                'avg_prep_time_min'   => $avgPrepTime,
                'channel_breakdown'   => [
                    'pos'        => $todayOrders->where('source', 'pos')->count(),
                    'pedidosya'  => $todayOrders->where('source', 'pedidosya')->count(),
                ],
            ],
        ]);
    }

    /**
     * Stream en tiempo real vía Server-Sent Events (SSE) para la app móvil Android.
     */
    public function stream(Request $request): StreamedResponse
    {
        $response = new StreamedResponse(function () {
            // Desactivar buffer de salida para envío instantáneo
            if (ob_get_level()) {
                ob_end_clean();
            }

            $lastCheck = now()->subSeconds(5);
            $heartbeatCounter = 0;

            // Enviar evento de conexión inicial
            echo "event: connected\n";
            echo "data: " . json_encode(['status' => 'connected', 'time' => now()->toIso8601String()]) . "\n\n";
            flush();

            // Loop de streaming (hasta 60 segundos por conexión antes de reconexión limpia)
            for ($i = 0; $i < 30; $i++) {
                if (connection_aborted()) {
                    break;
                }

                // Buscar órdenes creadas o actualizadas recientemente
                $recentUpdates = Sale::with([
                    'customer:id,name,ci_or_phone',
                    'cashier:id,name',
                    'items.product:id,name,image_url,is_weight_based',
                    'items.saleItemOptions.option:id,name,additional_price',
                    'items.saleItemOptions.optionGroup:id,name',
                    'deliveryOrder',
                ])
                ->where('status', 'completed')
                ->where('updated_at', '>=', $lastCheck)
                ->get();

                if ($recentUpdates->isNotEmpty()) {
                    $formatted = $recentUpdates->map(fn($s) => $this->formatKdsOrder($s));
                    echo "event: orders_updated\n";
                    echo "data: " . json_encode(['orders' => $formatted, 'count' => $recentUpdates->count()]) . "\n\n";
                    flush();
                    $lastCheck = now();
                }

                // Heartbeat / ping cada 15 segundos para mantener viva la conexión
                $heartbeatCounter++;
                if ($heartbeatCounter >= 7) {
                    echo "event: ping\n";
                    echo "data: " . json_encode(['ping' => time()]) . "\n\n";
                    flush();
                    $heartbeatCounter = 0;
                }

                sleep(2);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Formatea una venta en un DTO completo y amigable para KDS Android.
     */
    private function formatKdsOrder(Sale $sale): array
    {
        $now = now();
        $createdAt = Carbon::parse($sale->created_at);
        $elapsedMinutes = (int) $createdAt->diffInMinutes($now);
        $elapsedSeconds = (int) $createdAt->diffInSeconds($now);

        // Semáforo de tiempos para la cocina
        $statusColor = 'green';
        if ($elapsedMinutes >= 20) {
            $statusColor = 'red';
        } elseif ($elapsedMinutes >= 10) {
            $statusColor = 'yellow';
        }

        // Identificador amigable de la orden (ej: #001 o #POS-B2AB43)
        $shortId = strtoupper(substr($sale->id, 0, 6));
        $prefix = $sale->source === 'pedidosya' ? 'DELIV' : 'POS';
        
        $dailySeq = $sale->daily_sequence;
        $orderNumber = $sale->order_number;
        
        if ($dailySeq) {
            $displayCode = '#' . sprintf('%03d', $dailySeq);
        } else {
            $displayCode = "#{$prefix}-{$shortId}";
        }

        $items = $sale->items->map(function ($item) {
            // Agrupar opciones por grupo para lectura fácil en cocina
            $options = $item->saleItemOptions->map(function ($opt) {
                return [
                    'id'            => $opt->id,
                    'group_name'    => $opt->optionGroup?->name ?? 'Topping',
                    'option_name'   => $opt->option_name_snapshot ?? $opt->option?->name ?? 'Opción',
                    'extra_price'   => (float) ($opt->additional_price_snapshot ?? $opt->option?->additional_price ?? 0),
                    'quantity'      => $opt->quantity ?? 1,
                ];
            });

            return [
                'id'             => $item->id,
                'product_id'     => $item->product_id,
                'product_name'   => $item->product?->name ?? 'Producto',
                'product_image'  => $item->product?->image_url,
                'quantity'       => (float) $item->quantity,
                'unit_price'     => (float) $item->unit_price,
                'subtotal'       => (float) $item->subtotal,
                'is_takeaway'    => (bool) ($item->is_takeaway ?? false),
                'item_note'      => $item->item_note,
                'allergen_flags' => $item->allergen_flags ?? [],
                'options'        => $options,
            ];
        });

        $totalItemsCount = $items->count();
        $takeawayCount = $items->filter(fn($i) => !empty($i['is_takeaway']))->count();
        $isAllTakeaway = (bool) ($sale->is_takeaway || ($totalItemsCount > 0 && $takeawayCount === $totalItemsCount));
        $isMixed = !$isAllTakeaway && $takeawayCount > 0;

        $sourceLabel = 'Local / Mesa';
        if ($sale->source === 'pedidosya') {
            $sourceLabel = 'PedidosYa Delivery';
        } elseif ($isAllTakeaway) {
            $sourceLabel = 'Para Llevar';
        } elseif ($isMixed) {
            $sourceLabel = 'Mixto (Mesa/Llevar)';
        }

        $customerName = $sale->customer?->name;
        if (!$customerName) {
            if ($sale->source === 'pedidosya') {
                $customerName = 'Cliente PedidosYa';
            } elseif ($isAllTakeaway) {
                $customerName = 'Cliente (Para Llevar)';
            } elseif ($isMixed) {
                $customerName = 'Cliente (Mesa y Llevar)';
            } else {
                $customerName = 'Cliente en Local';
            }
        }

        return [
            'id'                     => $sale->id,
            'order_number'           => $orderNumber,
            'daily_sequence'         => $dailySeq,
            'display_code'           => $displayCode,
            'source'                 => $sale->source, // pos | pedidosya
            'is_takeaway'            => $isAllTakeaway,
            'is_mixed'               => $isMixed,
            'source_label'           => $sourceLabel,
            'preparation_status'     => $sale->preparation_status ?? 'received',
            'status_color'           => $statusColor,
            'elapsed_minutes'        => $elapsedMinutes,
            'elapsed_seconds'        => $elapsedSeconds,
            'created_at'             => $sale->created_at->toIso8601String(),
            'preparation_started_at' => $sale->preparation_started_at?->toIso8601String(),
            'ready_at'               => $sale->ready_at?->toIso8601String(),
            'delivered_at'           => $sale->delivered_at?->toIso8601String(),
            'customer_name'          => $customerName,
            'customer_phone'         => $sale->customer?->ci_or_phone,
            'cashier_name'           => $sale->cashier?->name ?? 'Cajero',
            'notes'                  => $sale->notes,
            'total_amount'           => (float) $sale->total_amount,
            'total_items_count'      => $items->sum('quantity'),
            'items'                  => $items,
        ];
    }
}
