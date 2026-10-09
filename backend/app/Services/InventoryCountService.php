<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;

/**
 * Conteo físico de inventario (plan de inventario, F3).
 *
 * Se escribe lo que hay y el sistema calcula la diferencia contra su stock **en el momento de confirmar** (no
 * contra el que se veía en pantalla: entre tanto pudo entrar una venta). Cada diferencia genera un ajuste ligado
 * al conteo, valorado al CPP del momento, y deja una línea con lo esperado, lo contado y la diferencia en Bs.
 *
 * El primer conteo de un insumo («línea de partida») ordena el stock con el que arrancó el sistema (compras
 * viejas sin cargar, stock inicial estimado): no cuenta como merma oculta en los reportes.
 */
class InventoryCountService
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * @param array<int, array{ingredient_id: int, counted: float|int|string}> $lines
     */
    public function confirm(array $lines, string $scope, ?string $notes, int $userId): InventoryCount
    {
        return DB::transaction(function () use ($lines, $scope, $notes, $userId) {
            $count = InventoryCount::create([
                'scope'       => $scope,
                'notes'       => $notes,
                'counted_by'  => $userId,
                'counted_at'  => now(),
            ]);

            $total = 0.0;
            $created = 0;

            foreach ($lines as $line) {
                $ingredient = Ingredient::lockForUpdate()->findOrFail($line['ingredient_id']);
                $expected = (float) $ingredient->current_stock;
                $counted = round((float) $line['counted'], 4);
                $difference = round($counted - $expected, 4);
                if (abs($difference) < InventoryService::EPSILON) {
                    $difference = 0.0;
                }

                $isBaseline = !InventoryCountLine::where('ingredient_id', $ingredient->id)->exists();
                $costs = $this->inventory->costColumns($ingredient, $difference);
                $signedCost = $costs['unit_cost'] !== null ? round($difference * $costs['unit_cost'], 2) : null;

                if ($difference !== 0.0) {
                    $this->inventory->applyStockChange($ingredient->id, $difference);

                    InventoryMovement::create([
                        'ingredient_id'    => $ingredient->id,
                        'quantity_changed' => $difference,
                        'type'             => 'adjustment',
                        'notes'            => trim(sprintf(
                            'Conteo #%d: el sistema tenía %s y se contó %s.%s',
                            $count->id,
                            $this->number($expected),
                            $this->number($counted),
                            $notes ? " {$notes}" : ''
                        )),
                        'performed_by'     => $userId,
                        'reference_type'   => 'inventory_count',
                        'reference_id'     => $count->id,
                    ] + $costs);
                }

                InventoryCountLine::create([
                    'inventory_count_id' => $count->id,
                    'ingredient_id'      => $ingredient->id,
                    'expected'           => $expected,
                    'counted'            => $counted,
                    'difference'         => $difference,
                    'unit_cost'          => $costs['unit_cost'],
                    'cost'               => $signedCost,
                    'is_baseline'        => $isBaseline,
                ]);

                $total += $signedCost ?? 0.0;
                $created++;
            }

            $count->update(['lines_count' => $created, 'difference_cost' => round($total, 2)]);

            return $count->load(['lines.ingredient', 'countedByUser:id,name']);
        });
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Hoja de conteo: los insumos con stock, con lo que dice el sistema, su costo y cuándo se contaron por última vez.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sheet(): array
    {
        $last = InventoryCountLine::query()
            ->join('inventory_counts', 'inventory_counts.id', '=', 'inventory_count_lines.inventory_count_id')
            ->selectRaw('inventory_count_lines.ingredient_id as ingredient_id, MAX(inventory_counts.counted_at) as counted_at')
            ->groupBy('inventory_count_lines.ingredient_id')
            ->pluck('counted_at', 'ingredient_id');

        return Ingredient::with('category:id,name')
            ->where('tracks_stock', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Ingredient $i) => [
                'id'              => $i->id,
                'name'            => $i->name,
                'unit'            => $i->unit,
                'category'        => $i->category?->name,
                'is_key'          => (bool) $i->is_key,
                'current_stock'   => (float) $i->current_stock,
                'unit_cost'       => round($i->costPerUnit(), 4),
                'last_counted_at' => $last->get($i->id) ? \Carbon\Carbon::parse($last->get($i->id))->toIso8601String() : null,
            ])
            ->all();
    }
}
