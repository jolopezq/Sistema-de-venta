<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsOhanaCatalog;
use Tests\TestCase;

/**
 * Inventario y componentes de costeo conviven sin romper ventas (hallazgos de la auditoría de inventario).
 */
class InventoryIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOhanaCatalog;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->buildCatalog();
    }

    public function test_inventory_list_shows_only_items_that_carry_stock(): void
    {
        $this->applyBaseRecipes();
        $pulpa = Ingredient::create(['name' => 'Pulpa de açaí', 'unit' => 'kg', 'current_stock' => 5, 'unit_cost' => 25]);

        $names = collect($this->actingAs($this->owner)->getJson('/api/ingredients')->assertOk()->json('data'))->pluck('name');

        $this->assertEquals([$pulpa->name], $names->all());
    }

    public function test_an_ingredient_used_in_a_recipe_cannot_be_deleted(): void
    {
        $leche = Ingredient::create(['name' => 'Leche condensada', 'unit' => 'kg', 'current_stock' => 0, 'unit_cost' => 28]);
        Recipe::create(['product_id' => $this->p['Classic Bowl']->id, 'ingredient_id' => $leche->id, 'quantity_required' => 0.025]);

        $this->actingAs($this->owner)->deleteJson("/api/ingredients/{$leche->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '1 receta'));
        $this->assertNotSoftDeleted($leche);

        // Si el producto ya no existe, la receta no lo impide
        $this->p['Classic Bowl']->delete();
        $this->actingAs($this->owner)->deleteJson("/api/ingredients/{$leche->id}")->assertNoContent();
    }

    public function test_a_sale_is_saved_even_if_an_ingredient_of_its_recipe_was_deleted(): void
    {
        $leche = Ingredient::create(['name' => 'Leche condensada', 'unit' => 'kg', 'current_stock' => 0, 'unit_cost' => 28]);
        Recipe::create(['product_id' => $this->p['Classic Bowl']->id, 'ingredient_id' => $leche->id, 'quantity_required' => 0.025]);
        $leche->delete(); // datos viejos: eliminado antes de existir la validación

        $id = (string) Str::uuid();
        $this->actingAs($this->owner)->postJson('/api/sales/sync', ['sales' => [[
            'id' => $id, 'subtotal' => 28, 'discount_amount' => 0, 'total_amount' => 28, 'status' => 'completed', 'source' => 'pos',
            'items' => [['product_id' => $this->p['Classic Bowl']->id, 'quantity' => 1, 'unit_price' => 28, 'subtotal' => 28]],
            'payments' => [['method' => 'cash', 'amount' => 28]],
        ]]])->assertOk()->assertJsonPath('data.synced.0', $id);

        $this->assertTrue(Sale::whereKey($id)->exists());
    }
}
