<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientComposition;
use App\Models\Option;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Composición de los componentes de costeo (docs/PLAN_INVENTARIO_REORDEN.md, F2).
 *
 * Un componente («Porción base açaí», «Kit descartable chico») no lleva stock. Su composición dice qué insumos
 * reales consume una unidad: «Porción base açaí» = 0,18 kg de pulpa. Este servicio es el único que sabe
 * expandirla, para que la venta (stock), el costeo y el catálogo del POS usen la misma regla:
 *
 *  - un insumo con stock se usa a sí mismo;
 *  - un componente con composición se expande en sus insumos (un solo nivel);
 *  - un componente sin composición no mueve stock y conserva su costo manual.
 *
 * Guarda en memoria los insumos y las composiciones; `refresh()` los vuelve a leer después de editarlos.
 */
class CompositionService
{
    private ?Collection $ingredients = null;
    private ?Collection $compositions = null;

    public function refresh(): void
    {
        $this->ingredients = Ingredient::withTrashed()->get()->keyBy('id');
        $this->compositions = IngredientComposition::all()->groupBy('component_id');
    }

    private function load(): void
    {
        if ($this->ingredients === null) {
            $this->refresh();
        }
    }

    public function ingredient(int $id): ?Ingredient
    {
        $this->load();

        return $this->ingredients->get($id);
    }

    public function hasComposition(int $componentId): bool
    {
        $this->load();

        return $this->compositions->has($componentId);
    }

    /** @return Collection<int, IngredientComposition> */
    public function compositionOf(int $componentId): Collection
    {
        $this->load();

        return $this->compositions->get($componentId, collect());
    }

    /**
     * Insumos con stock que se mueven al consumir `$quantity` de `$ingredient`.
     * Un insumo eliminado no impide guardar una venta ya cobrada: se omite.
     *
     * @return array<int, float> [ingredient_id => cantidad en la unidad del insumo]
     */
    public function stockUsage(?Ingredient $ingredient, float $quantity): array
    {
        if (!$ingredient || $ingredient->trashed()) {
            return [];
        }
        if ($ingredient->tracks_stock !== false) {
            return [$ingredient->id => $quantity];
        }

        $usage = [];
        foreach ($this->compositionOf($ingredient->id) as $row) {
            $target = $this->ingredient($row->ingredient_id);
            if (!$target || $target->trashed() || $target->tracks_stock === false) {
                continue;
            }
            $usage[$target->id] = ($usage[$target->id] ?? 0.0) + $quantity * (float) $row->quantity;
        }

        return $usage;
    }

    /**
     * Costo de una unidad: el de un componente con composición sale del costo promedio de sus insumos; en los
     * demás es su costo cargado (o su CPP si hay compras).
     *
     * @return array{0: float, 1: bool} costo por unidad y si falta el costo de algún insumo
     */
    public function unitCost(?Ingredient $ingredient): array
    {
        if (!$ingredient) {
            return [0.0, true];
        }

        if ($ingredient->tracks_stock === false && $this->hasComposition($ingredient->id)) {
            $cost = 0.0;
            $missing = false;
            foreach ($this->compositionOf($ingredient->id) as $row) {
                $target = $this->ingredient($row->ingredient_id);
                $targetCost = $target && !$target->trashed() ? $target->costPerUnit() : 0.0;
                $missing = $missing || $targetCost <= 0;
                $cost += (float) $row->quantity * $targetCost;
            }

            $cost = round($cost, 4);

            return [$cost, $missing || $cost <= 0];
        }

        $cost = $ingredient->costPerUnit();

        return [$cost, $cost <= 0];
    }

    /**
     * Filas de stock de la receta de un producto, ya expandidas, para el POS (también sin conexión).
     * `per_kg` indica que la cantidad es por kilo vendido (la fila de origen está en kg); el POS la multiplica
     * por los kilos pesados en los productos por peso y por las unidades en los demás.
     *
     * @return array<int, array{ingredient_id: int, quantity: float, per_kg: bool}>
     */
    public function productStockRows(Product $product): array
    {
        $rows = [];
        foreach ($product->recipes as $recipe) {
            $source = $this->ingredient($recipe->ingredient_id);
            $perKg = $source?->unit === 'kg';
            foreach ($this->stockUsage($source, (float) $recipe->quantity_required) as $id => $quantity) {
                $key = $id . '|' . (int) $perKg;
                $rows[$key] = [
                    'ingredient_id' => $id,
                    'quantity'      => ($rows[$key]['quantity'] ?? 0.0) + $quantity,
                    'per_kg'        => $perKg,
                ];
            }
        }

        return array_values(array_map(
            fn ($r) => ['ingredient_id' => $r['ingredient_id'], 'quantity' => round($r['quantity'], 4), 'per_kg' => $r['per_kg']],
            $rows
        ));
    }

    /**
     * Filas de stock del ajuste de una opción (Tamaño Ohana, Sin granola…), ya expandidas. La cantidad puede
     * ser negativa: devuelve stock.
     *
     * @return array<int, array{ingredient_id: int, quantity: float}>
     */
    public function optionStockRows(Option $option): array
    {
        $rows = [];
        foreach ($option->recipes as $recipe) {
            $source = $this->ingredient($recipe->ingredient_id);
            foreach ($this->stockUsage($source, (float) $recipe->quantity_delta) as $id => $quantity) {
                $rows[$id] = ($rows[$id] ?? 0.0) + $quantity;
            }
        }

        $out = [];
        foreach ($rows as $id => $quantity) {
            if (abs($quantity) >= 0.00005) {
                $out[] = ['ingredient_id' => $id, 'quantity' => round($quantity, 4)];
            }
        }

        return $out;
    }

    /**
     * Agrega `stock_recipe` a los productos y a sus opciones (el catálogo del POS lo envía ya expandido, así el
     * navegador no repite la regla). Los productos deben traer cargadas `recipes` y `optionGroups.options.recipes`.
     */
    public function attachStockRecipes(iterable $products): void
    {
        $this->refresh();
        foreach ($products as $product) {
            $product->setAttribute('stock_recipe', $this->productStockRows($product));
            foreach ($product->optionGroups as $group) {
                foreach ($group->options as $option) {
                    $option->setAttribute('stock_recipe', $this->optionStockRows($option));
                }
            }
        }
    }
}
