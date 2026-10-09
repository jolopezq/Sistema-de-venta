<?php

namespace App\Services;

use App\Models\FinanceExpenseItem;
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
     * Entrada de mercadería por una línea de compra: sube el stock y recalcula el Costo Promedio Ponderado (CPP).
     *
     * CPP = (stock × CPP vigente + cantidad × costo nuevo) ÷ stock total. El CPP vigente es el promedio
     * guardado o, si todavía no hay compras, el último costo cargado. Con stock en cero o negativo (se
     * vendió sin cargar la compra) no hay inventario que promediar: el CPP pasa a ser el costo de la compra.
     *
     * Se invoca dentro de la transacción de PurchaseService.
     */
    public function receivePurchaseItem(FinanceExpenseItem $item, int $userId, string $label): void
    {
        $current = Ingredient::lockForUpdate()->findOrFail($item->ingredient_id);
        $stock = (float) $current->current_stock;
        $quantity = (float) $item->stock_quantity;
        $unitCost = (float) $item->unit_cost;

        $newCpp = $stock > 0
            ? (($stock * $current->costPerUnit()) + ($quantity * $unitCost)) / ($stock + $quantity)
            : $unitCost;

        $current->unit_cost = $unitCost;
        $current->weighted_avg_cost = round($newCpp, 4);
        $current->save();

        $this->applyStockChange($current->id, $quantity);

        InventoryMovement::create([
            'ingredient_id'    => $current->id,
            'quantity_changed' => $quantity,
            'type'             => 'restock',
            'notes'            => $label,
            'performed_by'     => $userId,
            'reference_type'   => 'purchase_item',
            'reference_id'     => $item->id,
            'unit_cost'        => $unitCost,
            'total_cost'       => round($quantity * $unitCost, 2),
        ]);
    }

    /**
     * Deshace la entrada de una línea de compra (al editar o eliminar la compra): resta lo ingresado y deja
     * un movimiento `purchase_reversal` ligado a la línea. El CPP se recalcula después con recalculateCost().
     */
    public function reversePurchaseItem(FinanceExpenseItem $item, int $userId, string $label): void
    {
        $quantity = (float) $item->stock_quantity;
        $this->applyStockChange($item->ingredient_id, -$quantity);

        InventoryMovement::create([
            'ingredient_id'    => $item->ingredient_id,
            'quantity_changed' => -$quantity,
            'type'             => 'purchase_reversal',
            'notes'            => $label,
            'performed_by'     => $userId,
            'reference_type'   => 'purchase_item',
            'reference_id'     => $item->id,
            'unit_cost'        => $item->unit_cost,
            'total_cost'       => round($quantity * (float) $item->unit_cost, 2),
        ]);
    }

    /**
     * Recalcula el CPP repitiendo, en orden, los movimientos del insumo sin las compras ya revertidas.
     * Es la forma exacta de «quitar» una compra de un promedio móvil. Devuelve false si no se puede (movimientos
     * antiguos sin costo, o stock que no coincide con la suma de sus movimientos): el CPP queda como estaba.
     */
    public function recalculateCost(Ingredient $ingredient): bool
    {
        $movements = InventoryMovement::where('ingredient_id', $ingredient->id)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'type', 'quantity_changed', 'unit_cost', 'reference_type', 'reference_id']);

        $reversedItems = $movements->where('type', 'purchase_reversal')->pluck('reference_id')->all();
        $stock = 0.0;
        $cpp = 0.0;
        $lastCost = null;

        foreach ($movements as $m) {
            $quantity = (float) $m->quantity_changed;

            if ($m->type === 'purchase_reversal') {
                continue; // anula a su compra: ninguna de las dos cuenta
            }
            if ($m->type === 'restock' && $m->reference_type === 'purchase_item' && in_array($m->reference_id, $reversedItems, true)) {
                continue;
            }

            if ($m->type === 'initial' || $m->type === 'restock') {
                if ($m->unit_cost === null) {
                    return false;
                }
                $cost = (float) $m->unit_cost;
                $cpp = $stock > 0 ? (($stock * $cpp) + ($quantity * $cost)) / ($stock + $quantity) : $cost;
                $lastCost = $cost;
            }
            $stock += $quantity;
        }

        $fresh = Ingredient::findOrFail($ingredient->id);
        if (abs($stock - (float) $fresh->current_stock) > 0.001) {
            return false;
        }

        $fresh->weighted_avg_cost = round($cpp, 4);
        if ($lastCost !== null) {
            $fresh->unit_cost = $lastCost;
        }
        $fresh->save();

        return true;
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
                    'unit_cost'        => $cost,
                    'total_cost'       => round($initial * $cost, 2),
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
     * Una fila de la receta puede ser un insumo con stock o un componente de costeo con composición («Porción
     * base açaí»): este se expande en los insumos reales que consume (un solo nivel). Un componente sin
     * composición no mueve stock.
     *
     * En un producto por peso, las filas en kg (la receta se expresa por kilo vendido: 1 kg de helado por kg)
     * se multiplican por los gramos ÷ 1000 de la línea, igual que el costeo; las demás (envase, cucharilla) van
     * una vez por unidad vendida.
     *
     * Se invoca desde SaleSyncService, que ya tiene su transacción; las anidadas usan savepoints.
     */
    public function deductFromRecipe(Product $product, float $quantitySold, int $userId, SaleItem $saleItem): void
    {
        $grams = $saleItem->soldGrams($product);
        $kilosFactor = $grams !== null ? ($grams / 1000) * $quantitySold : $quantitySold;
        $composition = new CompositionService();

        DB::transaction(function () use ($product, $quantitySold, $kilosFactor, $grams, $userId, $saleItem, $composition) {
            foreach ($product->recipes as $recipe) {
                // Un insumo eliminado no debe impedir guardar una venta ya cobrada
                $source = $recipe->ingredient;
                if (!$source) {
                    continue;
                }

                $factor = $grams !== null && $source->unit === 'kg' ? $kilosFactor : $quantitySold;
                foreach ($composition->stockUsage($source, (float) $recipe->quantity_required * $factor) as $ingredientId => $quantity) {
                    $deduction = round($quantity, 4);
                    if (abs($deduction) < self::EPSILON) {
                        continue;
                    }

                    $this->applyStockChange($ingredientId, -$deduction);

                    InventoryMovement::create([
                        'ingredient_id'    => $ingredientId,
                        'quantity_changed' => -$deduction,
                        'type'             => 'sale',
                        'notes'            => "Venta deducida (UUID: {$saleItem->sale->id})" . ($source->id !== $ingredientId ? " · «{$source->name}»" : ''),
                        'performed_by'     => $userId,
                        'reference_type'   => 'sale_item',
                        'reference_id'     => $saleItem->id,
                    ]);
                }
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
        $composition = new CompositionService();

        DB::transaction(function () use ($saleItemOptions, $qtySold, $userId, $saleItem, $composition) {
            foreach ($saleItemOptions as $saleItemOption) {
                $option = \App\Models\Option::with('recipes.ingredient')->find($saleItemOption->option_id);
                if (!$option || $option->recipes->isEmpty()) {
                    continue;
                }

                foreach ($option->recipes as $optRecipe) {
                    $source = $optRecipe->ingredient;
                    $required = (float) $optRecipe->quantity_delta * $qtySold * $saleItemOption->quantity;

                    // Componente sin composición o insumo eliminado: no mueve stock
                    foreach ($composition->stockUsage($source, $required) as $ingredientId => $quantity) {
                        $deduction = round($quantity, 4);
                        if (abs($deduction) < self::EPSILON) {
                            continue;
                        }

                        $this->applyStockChange($ingredientId, -$deduction);

                        InventoryMovement::create([
                            'ingredient_id'    => $ingredientId,
                            'quantity_changed' => -$deduction,
                            'type'             => 'sale',
                            'notes'            => sprintf(
                                'Opción "%s" del producto "%s" (Venta: %s)',
                                $saleItemOption->option_name_snapshot,
                                $saleItem->product->name ?? '?',
                                $saleItem->sale->id
                            ) . ($source->id !== $ingredientId ? " · «{$source->name}»" : ''),
                            'performed_by'     => $userId,
                            'reference_type'   => 'sale_item_option',
                            'reference_id'     => $saleItemOption->id,
                        ]);
                    }
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
