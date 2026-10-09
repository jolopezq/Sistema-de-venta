<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Http\Requests\InventoryMovementRequest;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;

class InventoryMovementController extends Controller
{
    protected InventoryService $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Historial de movimientos de un insumo.
     */
    public function index(Ingredient $ingredient): JsonResponse
    {
        // Se puede hacer paginate en el futuro
        $movements = InventoryMovement::where('ingredient_id', $ingredient->id)
            ->with('performedByUser:id,name')
            ->latest()
            ->get();

        return response()->json($movements);
    }

    /**
     * Registra un nuevo movimiento de inventario.
     */
    public function store(InventoryMovementRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ingredient = Ingredient::findOrFail($validated['ingredient_id']);
        $userId = $request->user()->id;

        if (isset($validated['counted_stock'])) {
            // Conteo físico: la diferencia se calcula contra el stock del momento, no el que se veía en pantalla
            $this->inventoryService->recordCount(
                $ingredient,
                (float) $validated['counted_stock'],
                $validated['notes'] ?? null,
                $userId
            );
        } else {
            // Mermas (waste) o ajustes (adjustment)
            $this->inventoryService->recordManualMovement(
                $ingredient,
                (float) $validated['quantity_changed'],
                $validated['type'],
                $validated['waste_category'] ?? null,
                $validated['notes'] ?? null,
                $userId
            );
        }

        return response()->json([
            'message' => 'Movimiento de inventario registrado exitosamente.',
            'ingredient' => $ingredient->fresh()
        ], 201);
    }
}
