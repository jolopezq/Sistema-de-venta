<?php

namespace App\Http\Controllers;

use App\Http\Requests\WasteRequest;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use App\Services\WasteReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mermas (plan de inventario, F3, decisión D4): las anota el cajero desde Caja con un motivo obligatorio, o el
 * administrador desde Inventario. El dueño las revisa en el reporte del mes.
 */
class InventoryWasteController extends Controller
{
    public function __construct(
        protected InventoryService $inventory,
        protected WasteReportService $report,
    ) {}

    /**
     * Lo que necesita el formulario de merma: motivos, insumos con stock y lo anotado hoy.
     * El cajero ve solo lo que él anotó; el administrador, todo.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::now(config('app.timezone'))->toDateString();

        $recent = InventoryMovement::where('type', 'waste')
            ->whereBetween('created_at', ["{$today} 00:00:00", "{$today} 23:59:59"])
            ->when($user->role === 'cashier', fn ($q) => $q->where('performed_by', $user->id))
            ->with('performedByUser:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(30)
            ->get();
        $names = Ingredient::withTrashed()->whereIn('id', $recent->pluck('ingredient_id')->unique())->get(['id', 'name', 'unit'])->keyBy('id');

        return response()->json([
            'categories' => collect(InventoryMovement::WASTE_CATEGORIES)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'ingredients' => Ingredient::where('tracks_stock', true)->orderBy('name')
                ->get(['id', 'name', 'unit', 'current_stock'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'current_stock' => (float) $i->current_stock]),
            'today' => $recent->map(fn ($m) => [
                'id'         => $m->id,
                'created_at' => $m->created_at?->toIso8601String(),
                'ingredient' => $names->get($m->ingredient_id)?->name ?? 'Insumo eliminado',
                'unit'       => $names->get($m->ingredient_id)?->unit,
                'quantity'   => round(abs((float) $m->quantity_changed), 4),
                'category'   => InventoryMovement::WASTE_CATEGORIES[$m->waste_category] ?? 'Sin motivo',
                'user'       => $m->performedByUser?->name,
                'notes'      => $m->notes,
            ]),
        ]);
    }

    public function store(WasteRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ingredient = Ingredient::findOrFail($validated['ingredient_id']);
        $before = (float) $ingredient->current_stock;
        $quantity = (float) $validated['quantity'];

        $movement = $this->inventory->recordWaste(
            $ingredient,
            $quantity,
            $validated['waste_category'],
            $validated['notes'] ?? null,
            $request->user()->id
        );

        $fresh = $ingredient->fresh();

        return response()->json([
            'message' => 'Merma registrada.',
            'movement' => [
                'id'         => $movement->id,
                'quantity'   => round($quantity, 4),
                // El cajero no ve costos: el valor en Bs lo revisa el dueño en el reporte
                'cost'       => $request->user()->role !== 'cashier' && $movement->total_cost !== null ? (float) $movement->total_cost : null,
            ],
            'ingredient' => ['id' => $fresh->id, 'name' => $fresh->name, 'unit' => $fresh->unit, 'current_stock' => (float) $fresh->current_stock],
            // La merma se guarda igual: el stock del sistema puede estar mal y el conteo lo corrige
            'warning' => $quantity > $before + InventoryService::EPSILON
                ? 'Anotaste más de lo que el sistema tenía en stock. Se guardó igual; el próximo conteo ordenará el stock.'
                : null,
        ], 201);
    }

    /** Merma registrada y oculta del mes (solo administrador). */
    public function report(Request $request): JsonResponse
    {
        $now = Carbon::now(config('app.timezone'));
        $data = $request->validate([
            'year'  => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json($this->report->month((int) ($data['year'] ?? $now->year), (int) ($data['month'] ?? $now->month)));
    }
}
