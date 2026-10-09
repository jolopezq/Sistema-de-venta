<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryMovement;
use App\Models\User;
use Carbon\Carbon;

/**
 * Merma del mes (plan de inventario, F3).
 *
 *  - **Registrada:** las mermas que alguien anotó (derrame, vencido, consumo del personal…), valoradas al CPP del
 *    momento en que se anotaron.
 *  - **Oculta:** lo que el conteo físico encontró de menos (o de más) sin que nadie lo hubiera anotado. Porciones
 *    más grandes, fruta que se pasa, robos y errores de carga aparecen aquí. Es la suma de las diferencias de los
 *    conteos del mes, sin el primer conteo de cada insumo (que solo ordena el stock de partida).
 *
 * Equivale a «consumo real − consumo teórico − merma registrada», porque el consumo teórico son las ventas que
 * descontaron stock y todo lo demás que falta en el estante sale del conteo.
 */
class WasteReportService
{
    /** @return array{0: string, 1: string} inicio y fin del mes en hora local */
    private function range(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return [$start->toDateString() . ' 00:00:00', $start->copy()->endOfMonth()->toDateString() . ' 23:59:59'];
    }

    public function month(int $year, int $month): array
    {
        $range = $this->range($year, $month);

        return [
            'period'     => ['year' => $year, 'month' => $month],
            'registered' => $this->registered($range),
            'hidden'     => $this->hidden($range),
            'counts'     => $this->counts($range),
        ];
    }

    /** Solo los totales del mes (para la tarjeta del punto de equilibrio). */
    public function totals(int $year, int $month): array
    {
        $report = $this->month($year, $month);

        return [
            'registered' => $report['registered']['total_cost'],
            'hidden'     => $report['hidden']['net_loss'],
            'counts'     => $report['counts']['count'],
            // Líneas comparables: sin ellas (solo primeros conteos) la merma oculta aún no se puede medir
            'hidden_lines' => $report['hidden']['lines'],
        ];
    }

    private function registered(array $range): array
    {
        $movements = InventoryMovement::where('type', 'waste')
            ->whereBetween('created_at', $range)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        $ingredients = Ingredient::withTrashed()->whereIn('id', $movements->pluck('ingredient_id')->unique())->get()->keyBy('id');
        $users = User::whereIn('id', $movements->pluck('performed_by')->filter()->unique())->pluck('name', 'id');
        $labels = InventoryMovement::WASTE_CATEGORIES;

        $cost = fn ($m) => (float) ($m->total_cost ?? 0);

        $byCategory = $movements->groupBy(fn ($m) => $m->waste_category ?: 'other')
            ->map(fn ($group, $key) => [
                'category'  => $key,
                'label'     => $labels[$key] ?? 'Sin motivo',
                'cost'      => round($group->sum($cost), 2),
                'movements' => $group->count(),
            ])
            ->sortByDesc('cost')->values()->all();

        $byIngredient = $movements->groupBy('ingredient_id')
            ->map(function ($group, $id) use ($ingredients, $cost) {
                $ingredient = $ingredients->get($id);

                return [
                    'ingredient_id' => (int) $id,
                    'name'          => $ingredient?->name ?? 'Insumo eliminado',
                    'unit'          => $ingredient?->unit,
                    'quantity'      => round(abs((float) $group->sum('quantity_changed')), 4),
                    'cost'          => round($group->sum($cost), 2),
                    'movements'     => $group->count(),
                ];
            })
            ->sortByDesc('cost')->values()->all();

        $byUser = $movements->groupBy(fn ($m) => $m->performed_by ?? 0)
            ->map(fn ($group, $id) => [
                'user_id'   => $id ?: null,
                'name'      => $users->get($id) ?? 'Sin usuario',
                'cost'      => round($group->sum($cost), 2),
                'movements' => $group->count(),
            ])
            ->sortByDesc('cost')->values()->all();

        return [
            'total_cost'    => round($movements->sum($cost), 2),
            'movements'     => $movements->count(),
            'unpriced'      => $movements->whereNull('total_cost')->count(),
            'by_category'   => $byCategory,
            'by_ingredient' => $byIngredient,
            'by_user'       => $byUser,
            'recent'        => $movements->take(100)->map(fn ($m) => [
                'id'         => $m->id,
                'created_at' => $m->created_at?->toIso8601String(),
                'ingredient' => $ingredients->get($m->ingredient_id)?->name ?? 'Insumo eliminado',
                'unit'       => $ingredients->get($m->ingredient_id)?->unit,
                'quantity'   => round(abs((float) $m->quantity_changed), 4),
                'cost'       => $m->total_cost !== null ? (float) $m->total_cost : null,
                'category'   => $labels[$m->waste_category] ?? 'Sin motivo',
                'user'       => $users->get($m->performed_by),
                'notes'      => $m->notes,
            ])->values()->all(),
        ];
    }

    private function hidden(array $range): array
    {
        $lines = InventoryCountLine::whereHas('inventoryCount', fn ($q) => $q->whereBetween('counted_at', $range))->get();
        $ingredients = Ingredient::withTrashed()->whereIn('id', $lines->pluck('ingredient_id')->unique())->get()->keyBy('id');

        $baseline = $lines->where('is_baseline', true);
        $real = $lines->where('is_baseline', false);
        $cost = fn ($l) => (float) ($l->cost ?? 0);
        $differing = $real->filter(fn ($l) => abs((float) $l->difference) >= InventoryService::EPSILON);

        return [
            // Positivo = faltante neto (se perdió más de lo que se anotó); negativo = sobrante
            'net_loss'        => round(-$real->sum($cost), 2),
            'shortages'       => round(-$real->filter(fn ($l) => $cost($l) < 0)->sum($cost), 2),
            'surpluses'       => round($real->filter(fn ($l) => $cost($l) > 0)->sum($cost), 2),
            'lines'           => $real->count(),
            'unpriced'        => $differing->whereNull('cost')->count(),
            'baseline_lines'  => $baseline->count(),
            'by_ingredient'   => $differing
                ->sortBy(fn ($l) => $cost($l))
                ->map(fn ($l) => [
                    'ingredient_id' => $l->ingredient_id,
                    'name'          => $ingredients->get($l->ingredient_id)?->name ?? 'Insumo eliminado',
                    'unit'          => $ingredients->get($l->ingredient_id)?->unit,
                    'difference'    => (float) $l->difference,
                    'cost'          => $l->cost !== null ? (float) $l->cost : null,
                ])->values()->all(),
        ];
    }

    private function counts(array $range): array
    {
        $counts = InventoryCount::whereBetween('counted_at', $range)->orderByDesc('counted_at')->get(['id', 'counted_at', 'lines_count']);

        return [
            'count'   => $counts->count(),
            'last_at' => $counts->first()?->counted_at?->toIso8601String(),
        ];
    }
}
