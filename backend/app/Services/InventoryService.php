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
    public function deductFromRecipe(Product $product, float $quantitySold, int $userId, \App\Models\SaleItem $saleItem): void
    {
        DB::transaction(function () use ($product, $quantitySold, $userId, $saleItem) {
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
                    'notes'            => "Venta deducida (UUID: {$saleItem->sale->id})",
                    'performed_by'     => $userId,
                    'reference_type'   => 'sale_item',
                    'reference_id'     => $saleItem->id,
                ]);
            }
        });
    }
    /**
     * Descuenta stock basándose en las recetas de las OPCIONES seleccionadas.
     *
     * Este método se llama en adición a `deductFromRecipe` para procesar los
     * modificadores elegidos por el cliente (ej. Tamaño Ohana, Sin Granola).
     *
     * Soporta cantidades negativas en OptionRecipe para "opciones negativas":
     *  - "Sin Granola" → quantity_required = -20 → devuelve 20g al inventario.
     *  - "Extra Leche Condensada" → quantity_required = 30 → descuenta 30g.
     *
     * @param array   $modifiers   Array de modificadores seleccionados (del frontend).
     *                             Cada elemento tiene 'option_id' y 'option_name'.
     * @param float   $qtySold     Cantidad de unidades vendidas del producto.
     * @param int     $userId      ID del cajero.
     * @param string  $saleId      UUID de la venta (para auditoría).
     * @param Product $product     Solo para referencia en las notas de auditoría.
     */
    public function deductFromOptionRecipes(
        \Illuminate\Support\Collection $saleItemOptions,
        float $qtySold,
        int $userId,
        \App\Models\SaleItem $saleItem
    ): void {
        DB::transaction(function () use ($saleItemOptions, $qtySold, $userId, $saleItem) {
            foreach ($saleItemOptions as $saleItemOption) {
                $option = \App\Models\Option::with('recipes.ingredient')->find($saleItemOption->option_id);
                if (!$option || $option->recipes->isEmpty()) {
                    continue;
                }

                foreach ($option->recipes as $optRecipe) {
                    // quantity_delta can be negative (for exclusions like "Without Granola")
                    $deduction = $optRecipe->quantity_delta * $qtySold * $saleItemOption->quantity;
                    $ingredient = $optRecipe->ingredient;

                    // Descontar (o devolver si es negativo) stock
                    $ingredient->current_stock -= $deduction;
                    $ingredient->save();

                    // Movimiento de auditoría con contexto detallado
                    InventoryMovement::create([
                        'ingredient_id'    => $ingredient->id,
                        'quantity_changed' => -$deduction,
                        'type'             => 'sale',
                        'notes'            => sprintf(
                            'Opción "%s" del producto "%s" (Venta: %s)',
                            $saleItemOption->option_name_snapshot,
                            $saleItem->product->name ?? '?',
                            $saleItem->sale->id
                        ),
                        'performed_by'     => $userId,
                        'reference_type'   => 'sale_item_option',
                        'reference_id'     => $saleItemOption->id,
                    ]);
                }
            }
        });
    }

    /**
     * Registra un movimiento manual (ajuste o merma) de inventario.
     * Envuelto en DB::transaction() para atomicidad.
     */
    public function recordManualMovement(
        Ingredient $ingredient,
        float $quantityChanged,
        string $type,
        ?string $wasteCategory,
        ?string $notes,
        int $userId
    ): void {
        DB::transaction(function () use ($ingredient, $quantityChanged, $type, $wasteCategory, $notes, $userId) {
            $ingredient->current_stock += $quantityChanged;
            $ingredient->save();

            InventoryMovement::create([
                'ingredient_id'    => $ingredient->id,
                'quantity_changed' => $quantityChanged,
                'type'             => $type,
                'waste_category'   => $wasteCategory,
                'notes'            => $notes,
                'performed_by'     => $userId,
            ]);
        });
    }
}
