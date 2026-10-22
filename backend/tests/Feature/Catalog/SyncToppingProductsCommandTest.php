<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncToppingProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_cleanly_if_toppings_option_group_does_not_exist(): void
    {
        $this->artisan('catalog:sync-toppings')
            ->expectsOutput('No existe el grupo de opciones «Toppings».')
            ->assertExitCode(1);
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $group = OptionGroup::create(['name' => 'Toppings']);
        Option::create([
            'option_group_id'  => $group->id,
            'name'             => 'Ositos de gomita',
            'additional_price' => 4.00,
            'delivery_price'   => 5.00,
            'is_active'        => true,
        ]);

        $this->artisan('catalog:sync-toppings')
            ->assertSuccessful();

        $this->assertDatabaseMissing('categories', ['name' => 'Toppings']);
        $this->assertDatabaseMissing('products', ['name' => 'Ositos de gomita']);
    }

    public function test_apply_creates_category_products_and_recipes_idempotently(): void
    {
        $group = OptionGroup::create(['name' => 'Toppings']);
        $ingredient = Ingredient::create([
            'name'          => 'Gomitas',
            'unit'          => 'g',
            'current_stock' => 1000,
            'cost_price'    => 0.05,
            'tracks_stock'  => true,
        ]);

        $optGomitas = Option::create([
            'option_group_id'  => $group->id,
            'name'             => 'Ositos de gomita',
            'additional_price' => 4.00,
            'delivery_price'   => 5.00,
            'is_active'        => true,
        ]);
        \DB::table('option_recipes')->insert([
            'option_id'      => $optGomitas->id,
            'ingredient_id'  => $ingredient->id,
            'quantity_delta' => 20,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Opción sin costo adicional (debe ser ignorada)
        Option::create([
            'option_group_id'  => $group->id,
            'name'             => 'Sin costo',
            'additional_price' => 0.00,
            'is_active'        => true,
        ]);

        // Opción negativa tipo "Sin granola" (debe ser excluida)
        $optSin = Option::create([
            'option_group_id'  => $group->id,
            'name'             => 'Sin granola',
            'additional_price' => 4.00,
            'is_active'        => true,
        ]);
        \DB::table('option_recipes')->insert([
            'option_id'      => $optSin->id,
            'ingredient_id'  => $ingredient->id,
            'quantity_delta' => -20,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Primera ejecución con --apply
        $this->artisan('catalog:sync-toppings --apply')
            ->assertSuccessful();

        $category = Category::where('name', 'Toppings')->first();
        $this->assertNotNull($category);

        $product = Product::where('name', 'Ositos de gomita')->first();
        $this->assertNotNull($product);
        $this->assertEquals($category->id, $product->category_id);
        $this->assertEquals(4.00, (float) $product->price);
        $this->assertEquals(5.00, (float) $product->delivery_price);
        $this->assertFalse((bool) $product->available_on_pedidosya);

        // Receta copiada
        $recipe = Recipe::where('product_id', $product->id)->first();
        $this->assertNotNull($recipe);
        $this->assertEquals($ingredient->id, $recipe->ingredient_id);
        $this->assertEquals(20, (float) $recipe->quantity_required);

        // No debe crear "Sin granola" ni "Sin costo"
        $this->assertDatabaseMissing('products', ['name' => 'Sin granola']);
        $this->assertDatabaseMissing('products', ['name' => 'Sin costo']);

        // Segunda ejecución con --apply (debe ser idempotente)
        $this->artisan('catalog:sync-toppings --apply')
            ->assertSuccessful();

        $this->assertEquals(1, Product::where('name', 'Ositos de gomita')->count());
    }
}
