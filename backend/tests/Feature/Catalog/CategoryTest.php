<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Category;
use App\Models\Product;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function test_cannot_delete_category_that_contains_products()
    {
        $category = Category::create(['name' => 'Bebidas']);
        Product::create([
            'name'           => 'Frapu de cafe',
            'category_id'    => $category->id,
            'price'          => 15.00,
            'printer_target' => 'kitchen',
            'is_active'      => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->deleteJson('/api/categories/' . $category->id);

        $response->assertStatus(422)
                 ->assertJson([
                     'message' => 'No se puede eliminar la categoría porque contiene productos asociados. Elimina o reasigna los productos primero.'
                 ]);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    /** @test */
    public function test_can_delete_empty_category()
    {
        $category = Category::create(['name' => 'Categoria Vacia']);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->deleteJson('/api/categories/' . $category->id);

        $response->assertStatus(204);
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    /** @test */
    public function test_catalog_does_not_return_products_from_deleted_categories()
    {
        $category = Category::create(['name' => 'Postres']);
        $product = Product::create([
            'name'           => 'Cheesecake',
            'category_id'    => $category->id,
            'price'          => 20.00,
            'printer_target' => 'kitchen',
            'is_active'      => true,
        ]);

        // Simulating an orphaned product if category was soft-deleted
        $category->delete();

        $response = $this->actingAs($this->user, 'sanctum')
                         ->getJson('/api/catalog');

        $response->assertStatus(200);
        $response->assertJsonMissing(['name' => 'Cheesecake']);
    }
}
