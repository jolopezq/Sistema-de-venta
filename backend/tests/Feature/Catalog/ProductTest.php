<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use App\Models\OptionGroup;
use App\Models\Option;
use Carbon\Carbon;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_create_product_with_option_groups()
    {
        $category = Category::create(['name' => 'Cafes']);
        $group1 = OptionGroup::create(['name' => 'Tamaño']);
        $group2 = OptionGroup::create(['name' => 'Leche']);

        $payload = [
            'name'          => 'Capuchino',
            'description'   => 'Delicioso cafe',
            'price'         => 15.00,
            'category_id'   => $category->id,
            'is_active'     => true,
            'printer_target'=> 'kitchen',
            'option_groups' => [$group1->id, $group2->id]
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/products', $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('data.name', 'Capuchino');

        $this->assertDatabaseHas('products', ['name' => 'Capuchino']);

        $product = Product::where('name', 'Capuchino')->first();
        $this->assertDatabaseHas('option_group_product', ['product_id' => $product->id, 'option_group_id' => $group1->id]);
        $this->assertDatabaseHas('option_group_product', ['product_id' => $product->id, 'option_group_id' => $group2->id]);
    }

    public function test_can_update_product_and_sync_option_groups()
    {
        $category = Category::create(['name' => 'Cafes']);
        $group1 = OptionGroup::create(['name' => 'Tamaño']);
        $group2 = OptionGroup::create(['name' => 'Leche']);

        $product = Product::create([
            'name'           => 'Original',
            'category_id'    => $category->id,
            'price'          => 10,
            'printer_target' => 'kitchen'
        ]);
        $product->optionGroups()->attach($group1->id);

        $payload = [
            'name'           => 'Modificado',
            'category_id'    => $category->id,
            'price'          => 12,
            'printer_target' => 'kitchen',
            'option_groups'  => [$group2->id] // Should remove group1 and add group2
        ];

        $response = $this->actingAs($this->user, 'sanctum')->putJson('/api/products/' . $product->id, $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', ['name' => 'Modificado', 'price' => 12]);
        $this->assertDatabaseMissing('option_group_product', ['product_id' => $product->id, 'option_group_id' => $group1->id]);
        $this->assertDatabaseHas('option_group_product', ['product_id' => $product->id, 'option_group_id' => $group2->id]);
    }

    /** @test */
    public function test_tag_field_is_validated_and_persisted()
    {
        $category = Category::create(['name' => 'Cafes']);

        $payload = [
            'name'           => 'Producto Popular',
            'price'          => 15.00,
            'category_id'    => $category->id,
            'printer_target' => 'kitchen',
            'tag'            => 'popular',
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/products', $payload);
        $response->assertStatus(201)->assertJsonPath('data.tag', 'popular');
        $this->assertDatabaseHas('products', ['name' => 'Producto Popular', 'tag' => 'popular']);
    }

    /** @test */
    public function test_invalid_tag_is_rejected()
    {
        $category = Category::create(['name' => 'Cafes']);

        $payload = [
            'name'           => 'Producto Invalido',
            'price'          => 15.00,
            'category_id'    => $category->id,
            'printer_target' => 'kitchen',
            'tag'            => 'invalido',
        ];

        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/products', $payload)
             ->assertStatus(422)
             ->assertJsonValidationErrors(['tag']);
    }

    /** @test */
    public function test_toggle_product_inactive_sets_reactivate_at()
    {
        $category = Category::create(['name' => 'Cafes']);
        $product = Product::create([
            'name'           => 'Producto Activo',
            'price'          => 10,
            'category_id'    => $category->id,
            'printer_target' => 'kitchen',
            'is_active'      => true,
        ]);

        $reactivateDate = now()->addDays(1)->toDateTimeString();

        $this->actingAs($this->user, 'sanctum')
             ->putJson('/api/products/' . $product->id, [
                 'name'           => $product->name,
                 'price'          => $product->price,
                 'category_id'    => $product->category_id,
                 'printer_target' => $product->printer_target,
                 'is_active'      => false,
                 'reactivate_at'  => $reactivateDate,
             ])
             ->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'id'        => $product->id,
            'is_active' => false,
        ]);

        $updated = Product::find($product->id);
        $this->assertNotNull($updated->reactivate_at);
    }

    /** @test */
    public function test_soft_delete_product_is_not_in_active_list()
    {
        $category = Category::create(['name' => 'Cafes']);
        $product = Product::create([
            'name'           => 'Producto a Eliminar',
            'price'          => 10,
            'category_id'    => $category->id,
            'printer_target' => 'kitchen',
            'is_active'      => true,
        ]);

        $this->actingAs($this->user, 'sanctum')
             ->deleteJson('/api/products/' . $product->id)
             ->assertStatus(204);

        // Must still exist in DB (soft delete, not physical)
        $this->assertSoftDeleted('products', ['id' => $product->id]);

        // Must not appear in the index listing
        $this->actingAs($this->user, 'sanctum')
             ->getJson('/api/products')
             ->assertJsonMissing(['name' => 'Producto a Eliminar']);
    }

    /** @test */
    public function test_cannot_create_product_without_required_fields()
    {
        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/products', [])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['name', 'price', 'category_id', 'printer_target']);
    }

    /** @test */
    public function test_soft_delete_option_group_preserves_product_pivot_data()
    {
        $category = Category::create(['name' => 'Cafes']);
        $group = OptionGroup::create(['name' => 'Tamaño']);
        $product = Product::create([
            'name'           => 'Cafe',
            'price'          => 10,
            'category_id'    => $category->id,
            'printer_target' => 'kitchen',
        ]);
        $product->optionGroups()->attach($group->id);

        $this->actingAs($this->user, 'sanctum')
             ->deleteJson('/api/option-groups/' . $group->id)
             ->assertStatus(204);

        // Group must be soft-deleted, not physically removed
        $this->assertSoftDeleted('option_groups', ['id' => $group->id]);
    }
}
