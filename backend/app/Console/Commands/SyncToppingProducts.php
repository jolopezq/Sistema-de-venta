<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea la categoría «Toppings» y un producto por cada topping vendible suelto, copiando
 * precio y consumo de inventario desde las opciones del grupo «Toppings».
 *
 * Es idempotente: no duplica ni pisa productos que ya existen con el mismo nombre en la categoría.
 * Sin --apply solo muestra lo que haría.
 */
class SyncToppingProducts extends Command
{
    protected $signature = 'catalog:sync-toppings {--apply : Escribe los cambios (sin esto es solo una simulación)}';

    protected $description = 'Crea productos vendibles sueltos a partir de las opciones del grupo Toppings';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $group = OptionGroup::whereRaw('lower(name) = ?', ['toppings'])->first();
        if (! $group) {
            $this->error('No existe el grupo de opciones «Toppings».');

            return self::FAILURE;
        }

        $options = Option::with('recipes')
            ->where('option_group_id', $group->id)
            ->where('is_active', true)
            ->where('additional_price', '>', 0)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            // «Sin granola», «Sin leche en polvo»…: devuelven stock, no son vendibles.
            ->reject(fn (Option $o) => $o->recipes->contains(fn ($r) => (float) $r->quantity_delta < 0))
            ->unique(fn (Option $o) => mb_strtolower(trim($o->name)));

        $this->info(($apply ? 'APLICANDO' : 'SIMULACIÓN').": {$options->count()} toppings candidatos");

        $run = function () use ($options, $apply) {
            $category = Category::whereRaw('lower(name) = ?', ['toppings'])->first();
            if (! $category && $apply) {
                $category = Category::create([
                    'name'       => 'Toppings',
                    'sort_order' => ((int) Category::max('sort_order')) + 1,
                ]);
            }

            $created = 0;
            foreach ($options as $option) {
                $exists = $category && Product::where('category_id', $category->id)
                    ->whereRaw('lower(name) = ?', [mb_strtolower(trim($option->name))])->exists();
                if ($exists) {
                    $this->line("  = {$option->name} (ya existe)");

                    continue;
                }

                $positive = $option->recipes->filter(fn ($r) => (float) $r->quantity_delta > 0);
                $note = $positive->isEmpty() ? '  ⚠ sin receta: no descontará inventario' : '';
                $this->line("  + {$option->name}  Bs ".number_format((float) $option->additional_price, 2).$note);

                if (! $apply) {
                    $created++;

                    continue;
                }

                $product = Product::create([
                    'name'                   => trim($option->name),
                    'price'                  => $option->additional_price,
                    'delivery_price'         => $option->delivery_price ?? $option->additional_price,
                    'category_id'            => $category->id,
                    'printer_target'         => 'kitchen',
                    'is_active'              => true,
                    'is_weight_based'        => false,
                    'available_on_pedidosya' => false,
                ]);
                foreach ($positive as $r) {
                    Recipe::create([
                        'product_id'        => $product->id,
                        'ingredient_id'     => $r->ingredient_id,
                        'quantity_required' => $r->quantity_delta,
                    ]);
                }
                $created++;
            }

            $this->info("Productos ".($apply ? 'creados' : 'a crear').": {$created}");
        };

        $apply ? DB::transaction($run) : $run();

        return self::SUCCESS;
    }
}
