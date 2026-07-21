<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use App\Models\OptionGroup;

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
            'name' => 'Capuchino',
            'description' => 'Delicioso cafe',
            'price' => 15.00,
            'category_id' => $category->id,
            'is_active' => true,
            'printer_target' => 'kitchen',
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
            'name' => 'Original',
            'category_id' => $category->id,
            'price' => 10,
            'printer_target' => 'kitchen'
        ]);
        $product->optionGroups()->attach($group1->id);

        $payload = [
            'name' => 'Modificado',
            'category_id' => $category->id,
            'price' => 12,
            'printer_target' => 'kitchen',
            'option_groups' => [$group2->id] // Should remove group1 and add group2
        ];

        $response = $this->actingAs($this->user, 'sanctum')->putJson('/api/products/' . $product->id, $payload);
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('products', ['name' => 'Modificado', 'price' => 12]);
        $this->assertDatabaseMissing('option_group_product', ['product_id' => $product->id, 'option_group_id' => $group1->id]);
        $this->assertDatabaseHas('option_group_product', ['product_id' => $product->id, 'option_group_id' => $group2->id]);
    }
}
