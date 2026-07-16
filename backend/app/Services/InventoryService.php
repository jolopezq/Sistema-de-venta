<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Registra un abastecimiento (restock) recalculando el Costo Promedio Ponderado (CPP).
     */
    public function recordRestock(Ingredient $ingredient, float $quantity, float $newUnitCost, int $userId): void
    {
        DB::transaction(function () use ($ingredient, $quantity, $newUnitCost, $userId) {
            $currentStock = $ingredient->current_stock;
            $currentCpp = $ingredient->weighted_avg_cost ?? 0;
            
            $newTotalStock = $currentStock + $quantity;
            
            // CPP = (StockActual * CppActual + CantidadNueva * CostoNuevo) / (StockActual + CantidadNueva)
            $newCpp = $newTotalStock > 0 
                ? (($currentStock * $currentCpp) + ($quantity * $newUnitCost)) / $newTotalStock
                : $newUnitCost;
            
            // Actualizar insumo
            $ingredient->current_stock = $newTotalStock;
            $ingredient->unit_cost = $newUnitCost;
            $ingredient->weighted_avg_cost = $newCpp;
            $ingredient->save();
            
            // Registrar movimiento
            InventoryMovement::create([
                'ingredient_id' => $ingredient->id,
                'quantity_changed' => $quantity,
                'type' => 'restock',
                'notes' => 'Ingreso de mercadería (CPP actualizado)',
                'performed_by' => $userId,
            ]);
        });
    }

    /**
     * Descuenta stock de los insumos basándose en la receta del producto vendido.
     */
    public function deductFromRecipe(Product $product, float $quantitySold, int $userId, string $saleId): void
    {
        foreach ($product->recipes as $recipe) {
            $deduction = $recipe->quantity_required * $quantitySold;
            $ingredient = $recipe->ingredient;
            
            $ingredient->current_stock -= $deduction;
            $ingredient->save();
            
            InventoryMovement::create([
                'ingredient_id' => $ingredient->id,
                'quantity_changed' => -$deduction,
                'type' => 'sale',
                'notes' => "Venta deducida (Venta UUID: {$saleId})",
                'performed_by' => $userId,
            ]);
        }
    }
}
