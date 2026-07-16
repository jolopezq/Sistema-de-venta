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
     *
     * La operación es atómica: si falla la actualización del insumo o el
     * registro del movimiento, ambos hacen rollback (buenas-practicas.md §5).
     */
    public function recordRestock(Ingredient $ingredient, float $quantity, float $newUnitCost, int $userId): void
    {
        DB::transaction(function () use ($ingredient, $quantity, $newUnitCost, $userId) {
            $currentStock = $ingredient->current_stock;
            $currentCpp   = $ingredient->weighted_avg_cost ?? 0;

            $newTotalStock = $currentStock + $quantity;

            // CPP = (StockActual × CppActual + CantidadNueva × CostoNuevo) / StockTotal
            $newCpp = $newTotalStock > 0
                ? (($currentStock * $currentCpp) + ($quantity * $newUnitCost)) / $newTotalStock
                : $newUnitCost;

            $ingredient->current_stock     = $newTotalStock;
            $ingredient->unit_cost         = $newUnitCost;
            $ingredient->weighted_avg_cost = $newCpp;
            $ingredient->save();

            InventoryMovement::create([
                'ingredient_id'    => $ingredient->id,
                'quantity_changed' => $quantity,
                'type'             => 'restock',
                'notes'            => 'Ingreso de mercadería (CPP actualizado)',
                'performed_by'     => $userId,
            ]);
        });
    }

    /**
     * Descuenta stock de los insumos basándose en la receta del producto vendido.
     *
     * CORRECCIÓN: Se envuelve en DB::transaction() para garantizar que el
     * descuento de TODOS los insumos de la receta sea atómico. Si falla el
     * descuento de un insumo (ej. stock negativo), se revierte el descuento
     * de los anteriores y el inventario permanece íntegro.
     *
     * Este método suele invocarse desde SaleSyncService, que ya tiene su
     * propia transacción. Laravel gestiona transacciones anidadas con
     * savepoints, por lo que el comportamiento es siempre correcto.
     *
     * @param Product $product       Producto vendido (debe tener `recipes.ingredient` cargado).
     * @param float   $quantitySold  Cantidad de unidades vendidas.
     * @param int     $userId        ID del cajero que realizó la venta.
     * @param string  $saleId        UUID de la venta (para la auditoría de movimientos).
     */
    public function deductFromRecipe(Product $product, float $quantitySold, int $userId, string $saleId): void
    {
        DB::transaction(function () use ($product, $quantitySold, $userId, $saleId) {
            foreach ($product->recipes as $recipe) {
                $deduction = $recipe->quantity_required * $quantitySold;
                $ingredient = $recipe->ingredient;

                // Descuento de stock
                $ingredient->current_stock -= $deduction;
                $ingredient->save();

                // Movimiento de auditoría
                InventoryMovement::create([
                    'ingredient_id'    => $ingredient->id,
                    'quantity_changed' => -$deduction,
                    'type'             => 'sale',
                    'notes'            => "Venta deducida (UUID: {$saleId})",
                    'performed_by'     => $userId,
                ]);
            }
        });
    }
}
