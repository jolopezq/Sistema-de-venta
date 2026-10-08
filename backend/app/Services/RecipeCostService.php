<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Option;
use App\Models\OptionRecipe;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Costo por receta (docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md §3).
 *
 * Costo de un producto = Σ (cantidad de su receta × costo del componente) + Σ ajustes de las opciones elegidas.
 * En los productos por peso (Autoservicio) las líneas en kg se multiplican por los kg vendidos; las demás
 * (envase, cucharilla) van una vez por unidad. Una receta está "completa" cuando todos sus componentes tienen costo.
 */
class RecipeCostService
{
    /** Precio por gramo que usa el POS cuando el producto por peso no tiene uno (SalePricingService). */
    private const DEFAULT_PRICE_PER_GRAM = 0.08;

    private ?Collection $ingredients = null;
    private ?Collection $recipesByProduct = null;
    private ?Collection $recipesByOption = null;

    /** Vuelve a leer recetas y costos (después de editarlos). */
    public function refresh(): void
    {
        $this->ingredients = Ingredient::withTrashed()->get()->keyBy('id');
        $this->recipesByProduct = Recipe::all()->groupBy('product_id');
        $this->recipesByOption = OptionRecipe::all()->groupBy('option_id');
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

    public function hasRecipe(int $productId): bool
    {
        $this->load();

        return $this->recipesByProduct->has($productId);
    }

    /**
     * Costo unitario de un producto con sus opciones.
     *
     * @param array<int, float> $options [option_id => cantidad por unidad]
     * @return array{cost: float, has_recipe: bool, complete: bool, missing: array<int, string>, lines: array}
     */
    public function productCost(Product $product, array $options = [], ?float $grams = null): array
    {
        $this->load();

        $lines = [];
        $missing = [];
        $cost = 0.0;
        $kgSold = $product->is_weight_based ? max(0.0, (float) $grams) / 1000 : null;

        foreach ($this->recipesByProduct->get($product->id, collect()) as $row) {
            $ingredient = $this->ingredients->get($row->ingredient_id);
            $qty = (float) $row->quantity_required;
            if ($kgSold !== null && $ingredient?->unit === 'kg') {
                $qty *= $kgSold; // por peso: kg de la receta por kg vendido
            }
            [$lineCost, $isMissing] = $this->lineCost($ingredient, $qty);
            $cost += $lineCost;
            if ($isMissing) {
                $missing[] = $ingredient?->name ?? 'Componente eliminado';
            }
            $lines[] = ['source' => 'product', 'ingredient_id' => $row->ingredient_id, 'quantity' => $qty, 'cost' => round($lineCost, 4)];
        }

        foreach ($options as $optionId => $optionQty) {
            foreach ($this->recipesByOption->get($optionId, collect()) as $row) {
                $ingredient = $this->ingredients->get($row->ingredient_id);
                $qty = (float) $row->quantity_delta * (float) $optionQty;
                [$lineCost, $isMissing] = $this->lineCost($ingredient, $qty);
                $cost += $lineCost;
                if ($isMissing) {
                    $missing[] = $ingredient?->name ?? 'Componente eliminado';
                }
                $lines[] = ['source' => 'option', 'option_id' => $optionId, 'ingredient_id' => $row->ingredient_id, 'quantity' => $qty, 'cost' => round($lineCost, 4)];
            }
        }

        $hasRecipe = $this->recipesByProduct->has($product->id);

        return [
            'cost'       => round($cost, 4),
            'has_recipe' => $hasRecipe,
            'complete'   => $hasRecipe && empty($missing),
            'missing'    => array_values(array_unique($missing)),
            'lines'      => $lines,
        ];
    }

    /** Ajuste de costo de una opción (por unidad del producto). */
    public function optionCost(int $optionId): array
    {
        $this->load();

        $cost = 0.0;
        $missing = [];
        foreach ($this->recipesByOption->get($optionId, collect()) as $row) {
            $ingredient = $this->ingredients->get($row->ingredient_id);
            [$lineCost, $isMissing] = $this->lineCost($ingredient, (float) $row->quantity_delta);
            $cost += $lineCost;
            if ($isMissing) {
                $missing[] = $ingredient?->name ?? 'Componente eliminado';
            }
        }

        return [
            'cost'       => round($cost, 4),
            'has_recipe' => $this->recipesByOption->has($optionId),
            'complete'   => empty($missing),
            'missing'    => array_values(array_unique($missing)),
        ];
    }

    /**
     * Costo por receta de lo vendido en un mes (todas las ventas completadas: local, retroactivas y PedidosYa).
     */
    public function monthCost(int $year, int $month): array
    {
        $this->load();

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $items = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.created_at', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
            ->select('sale_items.*', 'sales.source as sale_source')
            ->with('saleItemOptions:id,sale_item_id,option_id,quantity')
            ->get();

        $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id')->unique())->get()->keyBy('id');
        $replacements = $this->replacementsFor($products);

        $empty = fn () => ['revenue' => 0.0, 'covered_revenue' => 0.0, 'covered_cost' => 0.0];
        $channels = ['local' => $empty(), 'pedidosya' => $empty()];
        $byProduct = [];
        $unitRevenue = 0.0;
        $unitCount = 0.0;

        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            if (!$product) {
                continue;
            }

            $channel = $item->sale_source === 'pedidosya' ? 'pedidosya' : 'local';
            $qty = (float) $item->quantity;
            $revenue = (float) $item->subtotal;
            $options = [];
            foreach ($item->saleItemOptions as $sio) {
                $options[$sio->option_id] = ($options[$sio->option_id] ?? 0) + (float) ($sio->quantity ?: 1);
            }

            $grams = null;
            if ($product->is_weight_based) {
                // Los gramos se guardan desde octubre de 2026; antes se deducen del precio cobrado
                $grams = $item->weight_grams !== null
                    ? (float) $item->weight_grams
                    : (float) $item->unit_price / ((float) $product->price_per_gram ?: self::DEFAULT_PRICE_PER_GRAM);
            }

            // Un producto eliminado y vuelto a crear con el mismo nombre se costea con la receta del actual
            $result = $this->productCost($replacements[$product->id] ?? $product, $options, $grams);
            $status = !$result['has_recipe'] ? 'no_recipe' : ($result['complete'] ? 'ok' : 'incomplete');

            $channels[$channel]['revenue'] += $revenue;
            if ($status === 'ok') {
                $channels[$channel]['covered_revenue'] += $revenue;
                $channels[$channel]['covered_cost'] += $result['cost'] * $qty;
            }

            if (!$product->is_weight_based) {
                $unitRevenue += $revenue;
                $unitCount += $qty;
            }

            $row = &$byProduct[$product->id];
            $row ??= [
                'product_id' => $product->id,
                'name'       => $product->name,
                // Eliminado y sin reemplazo: ya no aparece en «Costos y margen» para cargarle receta
                'deleted'    => $product->trashed() && !isset($replacements[$product->id]),
                'units'      => 0.0,
                'revenue'    => 0.0,
                'cost'       => 0.0,
                'status'     => $status,
                'missing'    => [],
            ];
            $row['units'] += $qty;
            $row['revenue'] += $revenue;
            $row['cost'] += $status === 'ok' ? $result['cost'] * $qty : 0.0;
            // El peor estado del producto en el mes (una venta con una opción sin costo lo deja incompleto)
            $row['status'] = $this->worse($row['status'], $status);
            $row['missing'] = array_values(array_unique(array_merge($row['missing'], $result['missing'])));
            unset($row);
        }

        $total = $empty();
        foreach ($channels as $key => $c) {
            $channels[$key] = $this->summarize($c);
            foreach (['revenue', 'covered_revenue', 'covered_cost'] as $field) {
                $total[$field] += $c[$field];
            }
        }
        $total = $this->summarize($total);

        $productRows = collect($byProduct)
            ->map(fn ($r) => array_merge($r, [
                'revenue' => round($r['revenue'], 2),
                'cost'    => round($r['cost'], 2),
                'margin_percent' => $r['status'] === 'ok' && $r['revenue'] > 0 ? round((1 - $r['cost'] / $r['revenue']) * 100, 1) : null,
            ]))
            ->sortByDesc('revenue')
            ->values();

        return [
            'total'      => $total,
            'channels'   => $channels,
            'products'   => $productRows->all(),
            'uncovered'  => $productRows->where('status', '!=', 'ok')->values()->all(),
            'unit_price' => $unitCount > 0 ? round($unitRevenue / $unitCount, 2) : null,
        ];
    }

    /**
     * Para cada producto eliminado sin receta, el producto vigente con el mismo nombre que sí la tiene.
     *
     * @return array<int, Product>
     */
    private function replacementsFor($products): array
    {
        $key = fn (string $name) => mb_strtolower(trim($name));
        $orphans = $products->filter(fn (Product $p) => $p->trashed() && !$this->recipesByProduct->has($p->id));
        if ($orphans->isEmpty()) {
            return [];
        }

        $current = Product::whereIn('name', $orphans->pluck('name')->unique()->all())
            ->get()
            ->filter(fn (Product $p) => $this->recipesByProduct->has($p->id))
            ->keyBy(fn (Product $p) => $key($p->name));

        $map = [];
        foreach ($orphans as $orphan) {
            if ($found = $current->get($key($orphan->name))) {
                $map[$orphan->id] = $found;
            }
        }

        return $map;
    }

    /** @return array{0: float, 1: bool} costo de la línea y si le falta costo al componente */
    private function lineCost(?Ingredient $ingredient, float $qty): array
    {
        if (!$ingredient) {
            return [0.0, $qty != 0.0];
        }
        $unitCost = $ingredient->costPerUnit();

        return [$qty * $unitCost, $qty != 0.0 && $unitCost <= 0];
    }

    private function worse(string $a, string $b): string
    {
        $rank = ['ok' => 0, 'incomplete' => 1, 'no_recipe' => 2];

        return $rank[$b] > $rank[$a] ? $b : $a;
    }

    private function summarize(array $c): array
    {
        return [
            'revenue'          => round($c['revenue'], 2),
            'covered_revenue'  => round($c['covered_revenue'], 2),
            'covered_cost'     => round($c['covered_cost'], 2),
            'cost_ratio'       => $c['covered_revenue'] > 0 ? round($c['covered_cost'] / $c['covered_revenue'], 4) : null,
            'coverage_percent' => $c['revenue'] > 0 ? round($c['covered_revenue'] / $c['revenue'] * 100, 1) : null,
        ];
    }
}
