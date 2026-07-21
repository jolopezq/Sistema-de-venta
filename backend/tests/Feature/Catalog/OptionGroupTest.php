<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use App\Models\OptionGroup;

class OptionGroupTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_create_option_group_with_nested_options()
    {
        $payload = [
            'name' => 'Tamaño',
            'min_selections' => 1,
            'max_selections' => 1,
            'options' => [
                ['name' => 'Mediano', 'additional_price' => 3.00, 'is_active' => true],
                ['name' => 'Grande', 'additional_price' => 5.00, 'is_active' => true],
            ]
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/option-groups', $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('data.name', 'Tamaño')
                 ->assertJsonCount(2, 'data.options');

        $this->assertDatabaseHas('option_groups', ['name' => 'Tamaño', 'min_selections' => 1]);
        $this->assertDatabaseHas('options', ['name' => 'Mediano', 'additional_price' => 3.00]);
        $this->assertDatabaseHas('options', ['name' => 'Grande', 'additional_price' => 5.00]);
    }

    public function test_can_update_option_group_and_sync_options()
    {
        $group = OptionGroup::create(['name' => 'Original', 'min_selections' => 0]);
        $option1 = $group->options()->create(['name' => 'Opt 1', 'additional_price' => 0]);

        $payload = [
            'name' => 'Modificado',
            'min_selections' => 0,
            'options' => [
                ['id' => $option1->id, 'name' => 'Opt 1 Mod', 'additional_price' => 2.00, 'is_active' => true],
                ['name' => 'Nueva', 'additional_price' => 1.00, 'is_active' => true],
            ]
        ];

        $response = $this->actingAs($this->user, 'sanctum')->putJson('/api/option-groups/' . $group->id, $payload);
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('option_groups', ['name' => 'Modificado']);
        $this->assertDatabaseHas('options', ['id' => $option1->id, 'name' => 'Opt 1 Mod', 'additional_price' => 2.00]);
        $this->assertDatabaseHas('options', ['name' => 'Nueva', 'additional_price' => 1.00]);
    }

    public function test_can_attach_products_to_option_group()
    {
        $group = OptionGroup::create(['name' => 'Toppings', 'min_selections' => 0]);
        $category = Category::create(['name' => 'Test']);
        $product1 = Product::create(['name' => 'P1', 'category_id' => $category->id, 'price' => 10]);
        $product2 = Product::create(['name' => 'P2', 'category_id' => $category->id, 'price' => 10]);

        $payload = [
            'product_ids' => [$product1->id, $product2->id]
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/option-groups/' . $group->id . '/attach-products', $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('option_group_product', ['option_group_id' => $group->id, 'product_id' => $product1->id]);
        $this->assertDatabaseHas('option_group_product', ['option_group_id' => $group->id, 'product_id' => $product2->id]);
    }
}
