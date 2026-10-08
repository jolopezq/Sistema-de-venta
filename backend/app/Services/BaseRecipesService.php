<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\OptionRecipe;
use App\Models\Product;
use App\Models\Recipe;
use App\Support\NameKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asistente «Cargar recetas base» (docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md §2).
 *
 * Propone los componentes de costo y las recetas del modelo por porciones a partir del catálogo:
 *  - bowl:     producto con el grupo de tamaños (Mediano / Grande / Ohana) → base + mix de frutas + toppings + kit chico
 *  - armable:  bowl con grupo de frutas (Arma tu Bowl) → sin mix en la base; cada fruta elegida suma 1/3 de mix
 *  - weight:   producto por peso (Autoservicio) → helado por kg vendido + envase y cucharilla
 *  - coffee:   café (categoría o nombre) → café, leche, azúcar, vaso con tapa (costos en cero para completar)
 * Las cifras son valores iniciales editables. Solo toca los productos y opciones que se elijan, y se puede repetir.
 */
class BaseRecipesService
{
    public const COMPONENTS = [
        'base'             => ['Porción base açaí (bowl chico)', 'unidades', 4.50],
        'mix'              => ['Mix de frutas (2–3 frutas)', 'unidades', 2.00],
        'toppings'         => ['Pack toppings base (granola + leche en polvo + condensada)', 'unidades', 1.50],
        'kit_chico'        => ['Kit descartable chico (vaso + tapa + cuchara)', 'unidades', 1.20],
        'kit_mediano'      => ['Kit descartable mediano', 'unidades', 1.90],
        'kit_grande'       => ['Kit descartable grande', 'unidades', 2.60],
        'kit_ohana'        => ['Kit descartable Ohana', 'unidades', 3.20],
        'topping_extra'    => ['Topping extra (porción)', 'unidades', 1.00],
        'helado_peso'      => ['Helado por peso (Autoservicio)', 'kg', 25.00],
        'envase_auto'      => ['Envase Autoservicio + cucharilla', 'unidades', 1.20],
        'cafe'             => ['Café en grano', 'kg', 0],
        'leche'            => ['Leche', 'litros', 0],
        'azucar'           => ['Azúcar (sobre)', 'unidades', 0],
        'vaso_cafe'        => ['Vaso de café + tapa', 'unidades', 0],
        'vaso_cafe_grande' => ['Vaso de café grande + tapa', 'unidades', 0],
        'bombilla'         => ['Bombilla', 'unidades', 0],
        'matcha'           => ['Matcha', 'kg', 0],
        'siropes'          => ['Siropes', 'litros', 0],
    ];

    private const RECIPES = [
        'bowl'    => [['base', 1], ['mix', 1], ['toppings', 1], ['kit_chico', 1]],
        'armable' => [['base', 1], ['toppings', 1], ['kit_chico', 1]],
        'weight'  => [['helado_peso', 1], ['envase_auto', 1]],
        // 18 g de café que rinden 2 tazas, 200 ml de leche, 1 sobre de azúcar, 1 vaso con tapa
        'coffee'  => [['cafe', 0.009], ['leche', 0.2], ['azucar', 1], ['vaso_cafe', 1]],
    ];

    private const SIZE_ADJUSTMENTS = [
        'mediano' => [['base', 0.4], ['kit_chico', -1], ['kit_mediano', 1]],
        'grande'  => [['base', 0.8], ['kit_chico', -1], ['kit_grande', 1]],
        'ohana'   => [['base', 2.0], ['kit_chico', -1], ['kit_ohana', 1]],
    ];

    private const COFFEE_SIZE_ADJUSTMENTS = [
        'grande' => [['leche', 0.1], ['vaso_cafe', -1], ['vaso_cafe_grande', 1]],
    ];

    public function preview(): array
    {
        $existing = $this->existingComponents();
        $products = $this->classifyProducts();
        $options = $this->optionAdjustments($products);

        $components = collect(self::COMPONENTS)->map(function ($c, $key) use ($existing) {
            [$name, $unit, $cost] = $c;
            $ingredient = $existing[$key] ?? null;

            return [
                'key'           => $key,
                'name'          => $ingredient?->name ?? $name,
                'unit'          => $ingredient?->unit ?? $unit,
                'unit_cost'     => $ingredient ? $ingredient->costPerUnit() : (float) $cost,
                'ingredient_id' => $ingredient?->id,
                'exists'        => $ingredient !== null,
            ];
        })->values()->all();

        return [
            'components' => $components,
            'products'   => $products->map(fn ($p) => [
                'product_id' => $p['product']->id,
                'name'       => $p['product']->name,
                'kind'       => $p['kind'],
                'has_recipe' => $p['product']->recipes()->exists(),
                'recipe'     => $this->rows(self::RECIPES[$p['kind']]),
            ])->values()->all(),
            'options'    => $options->map(fn ($o) => [
                'option_id'  => $o['option']->id,
                'name'       => $o['option']->name,
                'group'      => $o['option']->optionGroup?->name,
                'kind'       => $o['kind'],
                'has_recipe' => $o['option']->recipes()->exists(),
                'recipe'     => $this->rows($o['rows']),
            ])->values()->all(),
        ];
    }

    /**
     * Crea o actualiza los componentes y escribe las recetas elegidas (reemplaza las de esos productos y opciones).
     *
     * @param array $costs       [key => costo] editados en la vista previa
     * @param array $productIds  productos a los que se aplica la receta base
     * @param array $optionIds   opciones a las que se aplica su ajuste
     */
    public function apply(array $costs, array $productIds, array $optionIds): array
    {
        $preview = $this->preview();
        $productIds = array_map('intval', $productIds);
        $optionIds = array_map('intval', $optionIds);

        return DB::transaction(function () use ($preview, $costs, $productIds, $optionIds) {
            $ids = [];
            $usedKeys = collect($preview['products'])->whereIn('product_id', $productIds)->pluck('recipe')->flatten(1)->pluck('key')
                ->merge(collect($preview['options'])->whereIn('option_id', $optionIds)->pluck('recipe')->flatten(1)->pluck('key'))
                ->unique();

            $created = 0;
            foreach ($preview['components'] as $c) {
                $cost = array_key_exists($c['key'], $costs) ? max(0, (float) $costs[$c['key']]) : (float) $c['unit_cost'];
                if ($c['ingredient_id']) {
                    $ingredient = Ingredient::find($c['ingredient_id']);
                    if ($cost !== $ingredient->costPerUnit() && (float) $ingredient->weighted_avg_cost <= 0) {
                        $ingredient->update(['unit_cost' => $cost]);
                    }
                } elseif ($usedKeys->contains($c['key']) || array_key_exists($c['key'], $costs)) {
                    $ingredient = Ingredient::create([
                        'name'          => $c['name'],
                        'unit'          => $c['unit'],
                        'current_stock' => 0,
                        'unit_cost'     => $cost,
                        'type'          => 'costeo',
                        'tracks_stock'  => false,
                    ]);
                    $created++;
                } else {
                    continue;
                }
                $ids[$c['key']] = $ingredient->id;
            }

            $products = 0;
            foreach ($preview['products'] as $p) {
                if (!in_array($p['product_id'], $productIds, true)) {
                    continue;
                }
                Recipe::where('product_id', $p['product_id'])->get()->each->delete();
                foreach ($p['recipe'] as $row) {
                    Recipe::create(['product_id' => $p['product_id'], 'ingredient_id' => $ids[$row['key']], 'quantity_required' => $row['quantity']]);
                }
                $products++;
            }

            $options = 0;
            foreach ($preview['options'] as $o) {
                if (!in_array($o['option_id'], $optionIds, true)) {
                    continue;
                }
                OptionRecipe::where('option_id', $o['option_id'])->get()->each->delete();
                foreach ($o['recipe'] as $row) {
                    OptionRecipe::create(['option_id' => $o['option_id'], 'ingredient_id' => $ids[$row['key']], 'quantity_delta' => $row['quantity']]);
                }
                $options++;
            }

            return ['components_created' => $created, 'products' => $products, 'options' => $options];
        });
    }

    /** Componentes ya existentes (mismo nombre sin mayúsculas ni acentos y misma unidad). */
    private function existingComponents(): array
    {
        $ingredients = Ingredient::all();
        $found = [];
        foreach (self::COMPONENTS as $key => [$name, $unit]) {
            $match = $ingredients->first(fn ($i) => NameKey::make($i->name) === NameKey::make($name) && $i->unit === $unit);
            if ($match) {
                $found[$key] = $match;
            }
        }

        return $found;
    }

    /** @return Collection<int, array{product: Product, kind: string}> */
    private function classifyProducts(): Collection
    {
        $products = Product::with(['optionGroups.options', 'category'])->where('is_active', true)->orderBy('name')->get();

        return $products->map(function (Product $p) {
            if ($p->is_weight_based) {
                return ['product' => $p, 'kind' => 'weight'];
            }
            if ($p->optionGroups->contains(fn ($g) => $this->isBowlSizeGroup($g))) {
                $armable = $p->optionGroups->contains(fn ($g) => $this->isFruitGroup($g));

                return ['product' => $p, 'kind' => $armable ? 'armable' : 'bowl'];
            }
            if ($this->isCoffee($p)) {
                return ['product' => $p, 'kind' => 'coffee'];
            }

            return null;
        })->filter()->values();
    }

    /** Ajustes por opción de los grupos de los productos clasificados. */
    private function optionAdjustments(Collection $products): Collection
    {
        $result = collect();
        $seen = [];

        foreach ($products as ['product' => $product, 'kind' => $kind]) {
            foreach ($product->optionGroups as $group) {
                foreach ($group->options->where('is_active', true) as $option) {
                    if (isset($seen[$option->id])) {
                        continue;
                    }
                    $option->setRelation('optionGroup', $group);
                    $name = NameKey::make($option->name);
                    $rows = null;
                    $type = null;

                    if (in_array($kind, ['bowl', 'armable'], true)) {
                        if ($this->isBowlSizeGroup($group) && isset(self::SIZE_ADJUSTMENTS[$name])) {
                            [$rows, $type] = [self::SIZE_ADJUSTMENTS[$name], 'size'];
                        } elseif ($this->isFruitGroup($group)) {
                            [$rows, $type] = [[['mix', round(1 / 3, 4)]], 'fruit'];
                        } elseif (!$this->isBowlSizeGroup($group)) {
                            if (str_starts_with($name, 'sin ')) {
                                [$rows, $type] = [[['toppings', -0.33]], 'without'];
                            } elseif (str_contains($name, 'granola') || str_contains($name, 'leche')) {
                                [$rows, $type] = [[['toppings', 0.33]], 'base_extra'];
                            } else {
                                [$rows, $type] = [[['topping_extra', 1]], 'extra'];
                            }
                        }
                    } elseif ($kind === 'coffee' && isset(self::COFFEE_SIZE_ADJUSTMENTS[$name])) {
                        [$rows, $type] = [self::COFFEE_SIZE_ADJUSTMENTS[$name], 'coffee_size'];
                    }

                    if ($rows) {
                        $seen[$option->id] = true;
                        $result->push(['option' => $option, 'kind' => $type, 'rows' => $rows]);
                    }
                }
            }
        }

        return $result;
    }

    private function isBowlSizeGroup(OptionGroup $group): bool
    {
        $names = $group->options->map(fn ($o) => NameKey::make($o->name));

        return $names->contains('ohana') || $names->contains('mediano');
    }

    private function isFruitGroup(OptionGroup $group): bool
    {
        return str_contains(NameKey::make($group->name), 'fruta');
    }

    private function isCoffee(Product $p): bool
    {
        $text = NameKey::make($p->name . ' ' . ($p->category?->name ?? ''));

        return (bool) preg_match('/\b(cafe|capuchino|cappuccino|latte|espresso|americano|moca|mocha|frapu|frappe|matcha)\b/', $text);
    }

    private function rows(array $rows): array
    {
        return array_map(fn ($r) => ['key' => $r[0], 'quantity' => $r[1]], $rows);
    }
}
