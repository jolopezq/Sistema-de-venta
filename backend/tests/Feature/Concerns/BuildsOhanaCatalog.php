<?php

namespace Tests\Feature\Concerns;

use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Services\BaseRecipesService;

/**
 * Catálogo de prueba como el de Ohana y aplicación del asistente de recetas base.
 */
trait BuildsOhanaCatalog
{
    /** @var array<string, Product> */
    protected array $p = [];
    /** @var array<string, Option> */
    protected array $o = [];

    /** Catálogo como el de Ohana: bowls con tamaños y toppings, Arma tu Bowl con frutas, Autoservicio y Capuchino. */
    protected function buildCatalog(): void
    {
        $acai = Category::create(['name' => 'Acai', 'sort_order' => 1]);
        $arma = Category::create(['name' => 'Arma tu Bowl', 'sort_order' => 2]);
        $cafe = Category::create(['name' => 'Cafe caliente', 'sort_order' => 3]);

        $make = fn ($name, $cat, $price, $pya = null, $extra = []) => $this->p[$name] = Product::create(array_merge([
            'name' => $name, 'price' => $price, 'delivery_price' => $pya, 'category_id' => $cat->id, 'printer_target' => 'kitchen',
        ], $extra));
        $make('Classic Bowl', $acai, 28, 30);
        $make('Patriota', $acai, 28, 30);
        $make('Arma tu Bowl', $arma, 28, 32);
        $make('Autoservicio', $arma, 12, null, ['is_weight_based' => true, 'price_per_gram' => 0.12]);
        $make('Capuchino', $cafe, 16);

        $size = OptionGroup::create(['name' => 'Tamaño Acai', 'min_selections' => 0, 'max_selections' => 1, 'pedidosya_pricing' => true]);
        $toppings = OptionGroup::create(['name' => 'Toppings', 'min_selections' => 0, 'max_selections' => 10]);
        $fruits = OptionGroup::create(['name' => 'Frutas', 'min_selections' => 0, 'max_selections' => 3]);
        $coffeeSize = OptionGroup::create(['name' => 'Tamaño Cafe', 'min_selections' => 0, 'max_selections' => 1, 'pedidosya_pricing' => true]);

        foreach ([
            [$size, 'Mediano', 7, 8], [$size, 'Grande', 14, 16], [$size, 'Ohana', 32, 36],
            [$toppings, 'Sin granola', 0, 0], [$toppings, 'Sin leche en polvo', 0, 0], [$toppings, 'Ositos de gomita', 4, 5],
            [$toppings, 'Leche condensada', 4, 0], [$toppings, 'Extra granola', 4, 4],
            [$fruits, 'Banana', 0, 0], [$fruits, 'Frutilla', 0, 0], [$fruits, 'Kiwi', 0, 0],
            [$coffeeSize, 'Grande', 2, 0],
        ] as [$group, $name, $price, $pya]) {
            $key = $group->name === 'Tamaño Cafe' ? "Cafe {$name}" : $name;
            $this->o[$key] = Option::create(['option_group_id' => $group->id, 'name' => $name, 'additional_price' => $price, 'delivery_price' => $pya]);
        }

        foreach (['Classic Bowl', 'Patriota', 'Arma tu Bowl'] as $bowl) {
            $this->p[$bowl]->optionGroups()->attach([$size->id, $toppings->id]);
        }
        $this->p['Arma tu Bowl']->optionGroups()->attach($fruits->id);
        $this->p['Capuchino']->optionGroups()->attach($coffeeSize->id);
    }

    protected function applyBaseRecipes(array $costs = []): array
    {
        $service = app(BaseRecipesService::class);
        $preview = $service->preview();

        $costs = array_merge(collect($preview['components'])->mapWithKeys(fn ($c) => [$c['key'] => $c['unit_cost']])->all(), $costs);

        return $service->apply($costs, array_column($preview['products'], 'product_id'), array_column($preview['options'], 'option_id'));
    }

}
