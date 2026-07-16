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
     * Registra un nuevo movimiento de inventario.
     */
    public function store(InventoryMovementRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ingredient = Ingredient::findOrFail($validated['ingredient_id']);
        $userId = $request->user()->id;

        if ($validated['type'] === 'restock') {
            // Usa el servicio para recalcular CPP
            $this->inventoryService->recordRestock(
                $ingredient,
                $validated['quantity_changed'], // En un restock debe ser positivo
                $validated['unit_cost'],
                $userId
            );
        } else {
            // Mermas (waste) o ajustes (adjustment)
            $ingredient->current_stock += $validated['quantity_changed'];
            $ingredient->save();

            InventoryMovement::create([
                'ingredient_id' => $ingredient->id,
                'quantity_changed' => $validated['quantity_changed'],
                'type' => $validated['type'],
                'waste_category' => $validated['waste_category'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'performed_by' => $userId,
            ]);
        }

        return response()->json([
            'message' => 'Movimiento de inventario registrado exitosamente.',
            'ingredient' => $ingredient->fresh()
        ], 201);
    }
}
