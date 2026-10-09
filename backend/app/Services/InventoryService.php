<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;

/**
 * Único lugar donde cambia el stock de los insumos. Toda variación pasa por un movimiento y se aplica con una
 * sola sentencia `UPDATE … SET current_stock = current_stock ± n` (atómica: dos ventas a la vez no se pisan).
 */
class InventoryService
{
    /** Diferencias menores a media diezmilésima no mueven nada (la columna guarda 4 decimales). */
    private const EPSILON = 0.00005;

    /**
     * Suma (o resta, si es negativa) una cantidad al stock con una sola sentencia SQL.
     * Es el único punto que escribe `current_stock` después del alta.
     */
    public function applyStockChange(int $ingredientId, float $delta): void
    {
        if (abs($delta) < self::EPSILON) {
            return;
        }

        // ROUND evita que la aritmética binaria deje restos (3,7 − 0,025 = 3.6750000000000003) en el stock guardado
        $amount = sprintf('%.4F', round($delta, 4));
        Ingredient::whereKey($ingredientId)->update([
            'current_stock' => DB::raw("ROUND(current_stock + ({$amount}), 4)"),
        ]);
    }

    /**
     * Registra un abastecimiento (restock) recalculando el Costo Promedio Ponderado (CPP).
     *
     * CPP = (stock × CPP vigente + cantidad × costo nuevo) ÷ stock total. El CPP vigente es el promedio
     * guardado o, si todavía no hay compras, el último costo cargado. Con stock en cero o negativo (se
     * vendió sin cargar la compra) no hay inventario que promediar: el CPP pasa a ser el costo de la compra.
     *
     * La operación es atómica: si falla la actualización del insumo o el registro del movimiento, ambos
     * hacen rollback.
     */
    public function recordRestock(Ingredient $ingredient, float $quantity, float $newUnitCost, int $userId): void
    {
        DB::transaction(function () use ($ingredient, $quantity, $newUnitCost, $userId) {
            $current = Ingredient::lockForUpdate()->findOrFail($ingredient->id);
            $stock = (float) $current->current_stock;

            $newCpp = $stock > 0
                ? (($stock * $current->costPerUnit()) + ($quantity * $newUnitCost)) / ($stock + $quantity)
                : $newUnitCost;

            $current->unit_cost = $newUnitCost;
            $current->weighted_avg_cost = round($newCpp, 4);
            $current->save();

            $this->applyStockChange($current->id, $quantity);

            InventoryMovement::create([
                'ingredient_id'    => $current->id,
                'quantity_changed' => $quantity,
                'type'             => 'restock',
                'notes'            => 'Ingreso de mercadería (CPP actualizado)',
                'performed_by'     => $userId,
            ]);
        });
    }

    /**
     * Crea un insumo. El stock inicial no se escribe a mano: queda como el movimiento «initial» y el CPP
     * arranca en el costo cargado.
     */
    public function createIngredient(array $data, int $userId): Ingredient
    {
        return DB::transaction(function () use ($data, $userId) {
            $initial = round((float) ($data['current_stock'] ?? 0), 4);
            $cost = (float) ($data['unit_cost'] ?? 0);

            $ingredient = Ingredient::create(array_merge($data, [
                'current_stock'     => 0,
                'weighted_avg_cost' => $cost,
            ]));

            if ($initial > 0) {
                $this->applyStockChange($ingredient->id, $initial);
                InventoryMovement::create([
                    'ingredient_id'    => $ingredient->id,
                    'quantity_changed' => $initial,
                    'type'             => 'initial',
                    'notes'            => 'Stock inicial',
                    'performed_by'     => $userId,
                    'reference_type'   => 'manual',
                ]);
            }

            return $ingredient->refresh();
        });
    }

    /**
     * Edita los datos de un insumo. El stock y el CPP no se tocan desde aquí: el stock cambia solo por
     * movimientos (ingreso, merma, conteo, ventas) y el CPP por compras. Excepción: mientras no haya ninguna
     * compra registrada, corregir el costo corrige también el CPP (era el costo del alta).
     */
    public function updateIngredient(Ingredient $ingredient, array $data): Ingredient
    {
        unset($data['current_stock'], $data['weighted_avg_cost']);

        $costChanged = array_key_exists('unit_cost', $data)
            && abs((float) $data['unit_cost'] - (float) $ingredient->unit_cost) > self::EPSILON;

        if ($costChanged && !$ingredient->inventoryMovements()->where('type', 'restock')->exists()) {
            $data['weighted_avg_cost'] = $data['unit_cost'];
        }

        $ingredient->update($data);

        return $ingredient->fresh();
    }

    /**
     * Descuenta stock de los insumos basándose en la receta del producto vendido.
     *
     * En un producto por peso la receta se expresa por kilo vendido (p. ej. 1 kg de helado por kg), así que
     * se multiplica por los gramos ÷ 1000 de la línea, igual que el costeo. En los demás, por unidades.
     *
     * Se invoca desde SaleSyncService, que ya tiene su transacción; las anidadas usan savepoints.
     */
    public function deductFromRecipe(Product $product, float $quantitySold, int $userId, SaleItem $saleItem): void
    {
        $grams = $saleItem->soldGrams($product);
        $factor = $grams !== null ? ($grams / 1000) * $quantitySold : $quantitySold;

        DB::transaction(function () use ($product, $factor, $userId, $saleItem) {
            foreach ($product->recipes as $recipe) {
                $deduction = round($recipe->quantity_required * $factor, 4);
                $ingredient = $recipe->ingredient;

                // Componente de costo (porción de açaí, mix de frutas…): solo costea, no mueve stock.
                // Un insumo eliminado no debe impedir guardar una venta ya cobrada.
                if (!$ingredient || $ingredient->tracks_stock === false || abs($deduction) < self::EPSILON) {
                    continue;
                }

                $this->applyStockChange($ingredient->id, -$deduction);

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
     * Se llama además de `deductFromRecipe` para los modificadores elegidos por el cliente (Tamaño Ohana,
     * Sin Granola…). Una cantidad negativa en OptionRecipe devuelve stock:
     *  - "Sin Granola" → quantity_delta = -0.020 → devuelve 20 g.
     *  - "Extra Leche Condensada" → quantity_delta = 0.030 → descuenta 30 g.
     */
    public function deductFromOptionRecipes(
        \Illuminate\Support\Collection $saleItemOptions,
        float $qtySold,
        int $userId,
        SaleItem $saleItem
    ): void {
        DB::transaction(function () use ($saleItemOptions, $qtySold, $userId, $saleItem) {
            foreach ($saleItemOptions as $saleItemOption) {
                $option = \App\Models\Option::with('recipes.ingredient')->find($saleItemOption->option_id);
                if (!$option || $option->recipes->isEmpty()) {
                    continue;
                }

                foreach ($option->recipes as $optRecipe) {
                    $deduction = round($optRecipe->quantity_delta * $qtySold * $saleItemOption->quantity, 4);
                    $ingredient = $optRecipe->ingredient;

                    if (!$ingredient || $ingredient->tracks_stock === false || abs($deduction) < self::EPSILON) {
                        continue; // Componente de costo o insumo eliminado: no mueve stock
                    }

                    $this->applyStockChange($ingredient->id, -$deduction);

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
     * La cantidad ya viene con su signo: negativa si sale del inventario, positiva si entra.
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
            $this->applyStockChange($ingredient->id, $quantityChanged);

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

    /**
     * Conteo físico de un insumo: se indica lo que hay y el sistema calcula la diferencia contra su stock en
     * ese momento (no contra el que se veía en pantalla). Devuelve la diferencia aplicada (0 si coincide).
     */
    public function recordCount(Ingredient $ingredient, float $counted, ?string $notes, int $userId): float
    {
        return DB::transaction(function () use ($ingredient, $counted, $notes, $userId) {
            $current = Ingredient::lockForUpdate()->findOrFail($ingredient->id);
            $expected = (float) $current->current_stock;
            $difference = round($counted - $expected, 4);

            if (abs($difference) < self::EPSILON) {
                return 0.0;
            }

            $this->applyStockChange($current->id, $difference);

            $summary = sprintf(
                'Conteo físico: el sistema tenía %s y se contó %s',
                rtrim(rtrim(number_format($expected, 4, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($counted, 4, '.', ''), '0'), '.')
            );

            InventoryMovement::create([
                'ingredient_id'    => $current->id,
                'quantity_changed' => $difference,
                'type'             => 'adjustment',
                'notes'            => trim($summary . ($notes ? ". {$notes}" : '')),
                'performed_by'     => $userId,
                'reference_type'   => 'manual',
            ]);

            return $difference;
        });
    }
}
