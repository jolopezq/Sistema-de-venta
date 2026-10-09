<?php

namespace App\Http\Controllers;

use App\Http\Requests\InventoryMovementRequest;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Services\InventoryCountService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryMovementController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected InventoryCountService $counts,
    ) {}

    /**
     * Historial de movimientos de un insumo, del más reciente al más antiguo, paginado.
     * `?type=` filtra por tipo (sale, restock, waste, adjustment…).
     */
    public function index(Request $request, Ingredient $ingredient): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 30), 100));

        $movements = InventoryMovement::where('ingredient_id', $ingredient->id)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->with('performedByUser:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($movements);
    }

    /**
     * Registra una merma (`waste`) o el conteo de un solo insumo (`adjustment` + `counted_stock`).
     * Los conteos de varios insumos van a `POST /inventory/counts`.
     */
    public function store(InventoryMovementRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ingredient = Ingredient::findOrFail($validated['ingredient_id']);
        $userId = $request->user()->id;

        if ($validated['type'] === 'adjustment') {
            // La diferencia se calcula contra el stock del momento, no el que se veía en pantalla
            $this->counts->confirm(
                [['ingredient_id' => $ingredient->id, 'counted' => (float) $validated['counted_stock']]],
                'partial',
                $validated['notes'] ?? null,
                $userId
            );
        } else {
            $this->inventoryService->recordWaste(
                $ingredient,
                abs((float) $validated['quantity_changed']),
                $validated['waste_category'],
                $validated['notes'] ?? null,
                $userId
            );
        }

        return response()->json([
            'message' => 'Movimiento de inventario registrado exitosamente.',
            'ingredient' => $ingredient->fresh(),
        ], 201);
    }
}
