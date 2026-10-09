<?php

namespace App\Http\Controllers;

use App\Http\Requests\InventoryCountRequest;
use App\Models\InventoryCount;
use App\Services\InventoryCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Conteo físico de inventario (plan de inventario, F3). Solo administrador. */
class InventoryCountController extends Controller
{
    public function __construct(protected InventoryCountService $counts) {}

    /** Historial de conteos, del más reciente al más antiguo. */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $page = InventoryCount::with('countedByUser:id,name')
            ->withCount(['lines as differing_lines' => fn ($q) => $q->where('difference', '!=', 0)])
            ->orderByDesc('counted_at')->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($page->through(fn (InventoryCount $c) => $this->summary($c)));
    }

    /** Detalle de un conteo con todas sus líneas. */
    public function show(InventoryCount $inventoryCount): JsonResponse
    {
        return response()->json($this->detail($inventoryCount->load(['lines.ingredient', 'countedByUser:id,name'])));
    }

    /** Hoja para contar: insumos con stock, lo que dice el sistema, su costo y cuándo se contaron por última vez. */
    public function sheet(): JsonResponse
    {
        return response()->json(['ingredients' => $this->counts->sheet()]);
    }

    public function store(InventoryCountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $count = $this->counts->confirm(
            $validated['lines'],
            $validated['scope'] ?? 'partial',
            $validated['notes'] ?? null,
            $request->user()->id
        );

        return response()->json($this->detail($count), 201);
    }

    private function summary(InventoryCount $c): array
    {
        return [
            'id'              => $c->id,
            'counted_at'      => $c->counted_at?->toIso8601String(),
            'scope'           => $c->scope,
            'notes'           => $c->notes,
            'user'            => $c->countedByUser?->name,
            'lines_count'     => $c->lines_count,
            'differing_lines' => (int) ($c->differing_lines ?? 0),
            'difference_cost' => (float) $c->difference_cost,
        ];
    }

    private function detail(InventoryCount $c): array
    {
        return $this->summary($c) + [
            'lines' => $c->lines->map(fn ($l) => [
                'ingredient_id' => $l->ingredient_id,
                'name'          => $l->ingredient?->name ?? 'Insumo eliminado',
                'unit'          => $l->ingredient?->unit,
                'expected'      => (float) $l->expected,
                'counted'       => (float) $l->counted,
                'difference'    => (float) $l->difference,
                'unit_cost'     => $l->unit_cost !== null ? (float) $l->unit_cost : null,
                'cost'          => $l->cost !== null ? (float) $l->cost : null,
                'is_baseline'   => (bool) $l->is_baseline,
            ])->values()->all(),
        ];
    }
}
