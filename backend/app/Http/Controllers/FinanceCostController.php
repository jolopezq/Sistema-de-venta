<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\IngredientComposition;
use App\Models\Option;
use App\Models\OptionRecipe;
use App\Models\PedidosYaSettlement;
use App\Models\Product;
use App\Models\Recipe;
use App\Services\BaseRecipesService;
use App\Services\CompositionService;
use App\Services\RecipeCostService;
use App\Services\SalePricingService;
use App\Support\NameKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finanzas → Costos y margen: componentes de costo, recetas de productos y ajustes de opciones,
 * con el margen de cada producto en el local y en PedidosYa (docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md).
 */
class FinanceCostController extends Controller
{
    /** Peso de referencia para mostrar el costo de un producto por peso (venta típica del Autoservicio). */
    public const REFERENCE_GRAMS = 350;

    public function __construct(
        protected RecipeCostService $costs,
        protected BaseRecipesService $baseRecipes,
        protected CompositionService $composition,
    ) {}

    public function index(): JsonResponse
    {
        $this->costs->refresh();
        $this->composition->refresh();
        $platformRate = $this->platformRate();

        $ingredients = Ingredient::orderBy('name')->get();
        $recipeUse = Recipe::select('ingredient_id', DB::raw('COUNT(*) as n'))->groupBy('ingredient_id')->pluck('n', 'ingredient_id');
        $optionUse = OptionRecipe::select('ingredient_id', DB::raw('COUNT(*) as n'))->groupBy('ingredient_id')->pluck('n', 'ingredient_id');

        $components = $ingredients
            ->filter(fn ($i) => $i->tracks_stock === false || $recipeUse->has($i->id) || $optionUse->has($i->id))
            ->map(fn (Ingredient $i) => [
                'id'           => $i->id,
                'name'         => $i->name,
                'unit'         => $i->unit,
                'unit_cost'    => (float) $i->unit_cost,
                'cost'         => $this->composition->unitCost($i)[0],
                'from_purchases' => (float) $i->weighted_avg_cost > 0,
                'tracks_stock' => $i->tracks_stock !== false,
                'used_in'      => (int) ($recipeUse[$i->id] ?? 0) + (int) ($optionUse[$i->id] ?? 0),
                'composition'  => $this->compositionRows($i),
                // Falta el costo de algún insumo de la composición (sin compras ni costo cargado)
                'composition_complete' => $i->tracks_stock !== false || !$this->composition->hasComposition($i->id)
                    || !$this->composition->unitCost($i)[1],
            ])
            ->sortBy([['used_in', 'desc'], ['name', 'asc']])
            ->values();

        $products = Product::with(['optionGroups.options' => fn ($q) => $q->where('is_active', true), 'category:id,name', 'recipes'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $productRows = $products->map(fn (Product $p) => $this->productRow($p, $platformRate))->values();

        // Ajustes por opción: grupos de los productos activos
        $groups = $products->flatMap->optionGroups->where('is_active', true)->unique('id')->sortBy('name')->values();
        $optionRecipes = OptionRecipe::all()->groupBy('option_id');
        $groupRows = $groups->map(fn ($g) => [
            'id'      => $g->id,
            'name'    => $g->name,
            'options' => $g->options->map(function (Option $o) use ($optionRecipes) {
                $cost = $this->costs->optionCost($o->id);

                return [
                    'id'       => $o->id,
                    'name'     => $o->name,
                    'price'    => (float) $o->additional_price,
                    'recipe'   => $optionRecipes->get($o->id, collect())->map(fn ($r) => ['ingredient_id' => $r->ingredient_id, 'quantity' => (float) $r->quantity_delta])->values(),
                    'cost'     => $cost['cost'],
                    'complete' => $cost['complete'],
                    'missing'  => $cost['missing'],
                ];
            })->values(),
        ])->values();

        return response()->json([
            'components'      => $components,
            'ingredients'     => $ingredients->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'cost' => $this->composition->unitCost($i)[0], 'tracks_stock' => $i->tracks_stock !== false])->values(),
            'products'        => $productRows,
            'option_groups'   => $groupRows,
            'platform_rate'   => $platformRate,
            'reference_grams' => self::REFERENCE_GRAMS,
        ]);
    }

    public function storeComponent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:150'],
            'unit'      => ['required', 'in:kg,litros,unidades,sacos'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
        ]);
        $data['name'] = NameKey::tidy($data['name']);

        $existing = Ingredient::all()->first(fn ($i) => NameKey::make($i->name) === NameKey::make($data['name']));
        if ($existing) {
            throw ValidationException::withMessages(['name' => "Ya existe «{$existing->name}» ({$existing->unit})."]);
        }

        $ingredient = Ingredient::create($data + ['current_stock' => 0, 'type' => 'costeo', 'tracks_stock' => false]);

        return response()->json(['message' => 'Componente creado.', 'component' => $ingredient], 201);
    }

    public function updateComponent(Request $request, Ingredient $ingredient): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:150'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = NameKey::tidy($data['name']);
        }

        $ingredient->update($data);

        $message = (float) $ingredient->weighted_avg_cost > 0
            ? 'Costo guardado. Este insumo tiene compras registradas: el costeo usa su costo promedio.'
            : 'Costo guardado.';
        if ($ingredient->composition()->exists()) {
            $message = 'Guardado. Este componente tiene composición: su costo sale del costo promedio de sus insumos, no del que se cargó aquí.';
        }

        return response()->json(['message' => $message, 'component' => $ingredient]);
    }

    /**
     * Composición de un componente de costeo: qué insumos reales consume una unidad (cantidad en la unidad del
     * insumo: 0,18 = 180 g de pulpa). Reemplaza la anterior; sin filas, el componente vuelve a su costo manual y
     * deja de mover stock. No recalcula las ventas pasadas: lo descontado antes queda como se registró.
     */
    public function updateComposition(Request $request, Ingredient $ingredient): JsonResponse
    {
        if ($ingredient->tracks_stock !== false) {
            throw ValidationException::withMessages([
                'ingredient' => "«{$ingredient->name}» lleva su propio stock; solo los componentes de costeo tienen composición.",
            ]);
        }

        $data = $request->validate([
            'rows'                 => ['present', 'array', 'max:40'],
            'rows.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'rows.*.quantity'      => ['required', 'numeric', 'gt:0', 'max:100000'],
        ], [
            'rows.*.quantity.gt'       => 'Las cantidades deben ser mayores a 0.',
            'rows.*.ingredient_id.required' => 'Elige el insumo de cada fila.',
        ]);

        // Un mismo insumo dos veces se suma en una fila
        $rows = collect($data['rows'])
            ->groupBy('ingredient_id')
            ->map(fn ($g, $id) => ['ingredient_id' => (int) $id, 'quantity' => round($g->sum('quantity'), 4)])
            ->values();

        // Un solo nivel: el destino es siempre un insumo con stock (no otro componente ni uno eliminado)
        foreach ($rows as $i => $row) {
            $target = Ingredient::find($row['ingredient_id']);
            if (!$target || $target->tracks_stock === false) {
                $name = $target?->name ?? 'ese insumo';
                throw ValidationException::withMessages([
                    "rows.$i.ingredient_id" => "«{$name}» no lleva stock: la composición se arma con insumos reales (Inventario).",
                ]);
            }
        }

        DB::transaction(function () use ($ingredient, $rows) {
            IngredientComposition::where('component_id', $ingredient->id)->get()->each->delete();
            foreach ($rows as $row) {
                IngredientComposition::create(['component_id' => $ingredient->id] + $row);
            }
        });

        $this->costs->refresh();
        $this->composition->refresh();
        [$cost, $missing] = $this->composition->unitCost($ingredient->fresh());

        return response()->json([
            'message'   => $rows->isEmpty()
                ? "«{$ingredient->name}» sin composición: usa su costo cargado y no descuenta stock."
                : "Composición de «{$ingredient->name}» guardada. Desde ahora cada venta descuenta esos insumos.",
            'component' => [
                'id'                   => $ingredient->id,
                'composition'          => $this->compositionRows($ingredient),
                'cost'                 => round($cost, 4),
                'composition_complete' => $rows->isEmpty() || !$missing,
            ],
        ]);
    }

    /** @return array<int, array{ingredient_id: int, quantity: float}> */
    private function compositionRows(Ingredient $component): array
    {
        return $this->composition->compositionOf($component->id)
            ->map(fn ($r) => ['ingredient_id' => $r->ingredient_id, 'quantity' => (float) $r->quantity])
            ->values()
            ->all();
    }

    public function updateProductRecipe(Request $request, Product $product): JsonResponse
    {
        $rows = $this->validatedRows($request, false);

        DB::transaction(function () use ($product, $rows) {
            Recipe::where('product_id', $product->id)->get()->each->delete();
            foreach ($rows as $row) {
                Recipe::create(['product_id' => $product->id, 'ingredient_id' => $row['ingredient_id'], 'quantity_required' => $row['quantity']]);
            }
        });

        $this->costs->refresh();

        return response()->json(['message' => "Receta de {$product->name} guardada.", 'product' => $this->productRow($product->fresh(['optionGroups.options', 'category:id,name', 'recipes']), $this->platformRate())]);
    }

    public function updateOptionRecipe(Request $request, Option $option): JsonResponse
    {
        $rows = $this->validatedRows($request, true);

        DB::transaction(function () use ($option, $rows) {
            OptionRecipe::where('option_id', $option->id)->get()->each->delete();
            foreach ($rows as $row) {
                OptionRecipe::create(['option_id' => $option->id, 'ingredient_id' => $row['ingredient_id'], 'quantity_delta' => $row['quantity']]);
            }
        });

        $this->costs->refresh();

        return response()->json(['message' => "Ajuste de «{$option->name}» guardado.", 'cost' => $this->costs->optionCost($option->id)]);
    }

    public function baseRecipesPreview(): JsonResponse
    {
        return response()->json($this->baseRecipes->preview());
    }

    public function applyBaseRecipes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'costs'         => ['nullable', 'array'],
            'costs.*'       => ['numeric', 'min:0'],
            'product_ids'   => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'option_ids'    => ['nullable', 'array'],
            'option_ids.*'  => ['integer'],
        ]);

        $result = $this->baseRecipes->apply($data['costs'] ?? [], $data['product_ids'] ?? [], $data['option_ids'] ?? []);

        return response()->json([
            'message' => "Recetas base aplicadas: {$result['products']} productos y {$result['options']} opciones"
                . ($result['components_created'] ? ", {$result['components_created']} componentes nuevos." : '.'),
            'result'  => $result,
        ]);
    }

    /** Fila de un producto: receta, costo, precios y margen (y por cada opción de grupos de una sola elección). */
    private function productRow(Product $p, ?float $platformRate): array
    {
        $grams = $p->is_weight_based ? self::REFERENCE_GRAMS : null;
        $base = $this->costs->productCost($p, [], $grams);
        $localPrice = $p->is_weight_based
            ? round(self::REFERENCE_GRAMS * ((float) $p->price_per_gram ?: 0.08), 2)
            : (float) $p->price;
        $pyaPrice = $p->is_weight_based ? null : SalePricingService::productPrice($p, 'pedidosya');

        $variants = [];
        foreach ($p->optionGroups->where('is_active', true)->where('max_selections', 1) as $group) {
            foreach ($group->options->where('is_active', true) as $option) {
                $option->setRelation('optionGroup', $group);
                $cost = $this->costs->productCost($p, [$option->id => 1], $grams);
                $local = $localPrice + (float) $option->additional_price;
                $pya = $pyaPrice !== null ? $pyaPrice + SalePricingService::optionPrice($option, 'pedidosya') : null;
                $variants[] = [
                    'option_id'    => $option->id,
                    'name'         => $option->name,
                    'group'        => $group->name,
                    'cost'         => $cost['cost'],
                    'complete'     => $cost['complete'],
                    'price_local'  => $local,
                    'price_pya'    => $pya,
                    'margin_local' => $this->margin($local, $cost['cost']),
                    'margin_pya'   => $this->margin($pya, $cost['cost'], $platformRate),
                ];
            }
        }

        return [
            'id'              => $p->id,
            'name'            => $p->name,
            'category'        => $p->category?->name,
            'is_weight_based' => (bool) $p->is_weight_based,
            'recipe'          => $p->recipes->map(fn ($r) => ['ingredient_id' => $r->ingredient_id, 'quantity' => (float) $r->quantity_required])->values(),
            'has_recipe'      => $base['has_recipe'],
            'complete'        => $base['complete'],
            'missing'         => $base['missing'],
            'cost'            => $base['cost'],
            'price_local'     => $localPrice,
            'price_pya'       => $pyaPrice,
            'margin_local'    => $this->margin($localPrice, $base['cost']),
            'margin_pya'      => $this->margin($pyaPrice, $base['cost'], $platformRate),
            'variants'        => $variants,
        ];
    }

    /** % de margen; en PedidosYa, después de lo que se queda la plataforma. */
    private function margin(?float $price, float $cost, ?float $platformRate = null): ?float
    {
        if (!$price || $price <= 0) {
            return null;
        }
        $net = $price * (1 - ($platformRate ?? 0));

        return round(($net - $cost) / $price * 100, 1);
    }

    /** Lo que se queda PedidosYa de las ventas brutas en los últimos 90 días (null sin semanas importadas). */
    private function platformRate(): ?float
    {
        $since = now()->subDays(90)->toDateString();
        $rows = PedidosYaSettlement::where('end_date', '>=', $since)->get(['csv_gross_sales', 'total_payout']);
        $gross = (float) $rows->sum('csv_gross_sales');

        return $gross > 0 ? round(($gross - (float) $rows->sum('total_payout')) / $gross, 4) : null;
    }

    private function validatedRows(Request $request, bool $allowNegative): array
    {
        $data = $request->validate([
            'rows'                 => ['present', 'array'],
            'rows.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'rows.*.quantity'      => ['required', 'numeric', $allowNegative ? 'not_in:0' : 'gt:0'],
        ], [
            'rows.*.quantity.gt'     => 'Las cantidades de la receta deben ser mayores a 0.',
            'rows.*.quantity.not_in' => 'Una cantidad no puede ser 0 (usa un número negativo para restar).',
        ]);

        // Un mismo componente dos veces se suma en una línea
        return collect($data['rows'])
            ->groupBy('ingredient_id')
            ->map(fn ($g, $id) => ['ingredient_id' => (int) $id, 'quantity' => round($g->sum('quantity'), 4)])
            ->filter(fn ($r) => $r['quantity'] != 0)
            ->values()
            ->all();
    }
}
