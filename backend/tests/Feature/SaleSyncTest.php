<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Support\Str;

class SaleSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Prueba que el endpoint de sincronización guarda ventas, descuenta stock y maneja idempotencia.
     */
    public function test_it_syncs_offline_sales_and_deducts_inventory_and_is_idempotent(): void
    {
        // 1. Preparar datos
        $cashier = User::factory()->create();
        
        $ingredient = Ingredient::create([
            'name' => 'Acai',
            'unit' => 'kg',
            'current_stock' => 10,
        ]);
        
        $category = Category::create([
            'name' => 'Bowls',
            'sort_order' => 1,
        ]);
        
        $product = Product::create([
            'name' => 'Bowl Grande',
            'price' => 35,
            'category_id' => $category->id,
            'printer_target' => 'kitchen',
        ]);
        
        Recipe::create([
            'product_id' => $product->id,
            'ingredient_id' => $ingredient->id,
            'quantity_required' => 0.2, // 200g
        ]);

        $uuid = (string) Str::uuid();
        
        $payload = [
            'sales' => [
                [
                    'id' => $uuid,
                    'customer_id' => null,
                    'subtotal' => 35,
                    'discount_amount' => 0,
                    'total_amount' => 35,
                    'status' => 'completed',
                    'source' => 'pos',
                    'items' => [
                        [
                            'product_id' => $product->id,
                            'quantity' => 1,
                            'unit_price' => 35,
                            'subtotal' => 35,
                        ]
                    ],
                    'payments' => [
                        [
                            'method' => 'cash',
                            'amount' => 35,
                        ]
                    ]
                ]
            ]
        ];

        // 2. Ejecutar primera sincronización
        $response = $this->actingAs($cashier)->postJson('/api/sales/sync', $payload);
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('sales', ['id' => $uuid, 'total_amount' => 35]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $uuid, 'product_id' => $product->id]);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $uuid, 'method' => 'cash']);
        
        // Verificar deducción de inventario (10 - 0.2 = 9.8)
        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => 9.8,
        ]);
        
        // Verificar registro de movimiento
        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $ingredient->id,
            'quantity_changed' => -0.2,
            'type' => 'sale',
        ]);
        
        // 3. Ejecutar segunda sincronización (Idempotencia) - Simula reintento por red inestable
        $response2 = $this->actingAs($cashier)->postJson('/api/sales/sync', $payload);
        $response2->assertStatus(200);
        
        // El inventario debe seguir en 9.8, no se debe haber descontado de nuevo
        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => 9.8,
        ]);
    }
}
