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
        $response->assertJsonPath('data.synced.0', $uuid);
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
        $response2->assertJsonPath('data.synced.0', $uuid);
        
        // El inventario debe seguir en 9.8, no se debe haber descontado de nuevo
        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => 9.8,
        ]);
    }

    /**
     * Prueba que notas, alérgenos, estado de preparación y número de ticket del POS se persisten.
     */
    public function test_it_persists_notes_allergens_and_pos_order_number(): void
    {
        $cashier = User::factory()->create();
        $product = $this->createProduct();
        $uuid = (string) Str::uuid();

        $sale = $this->salePayload($uuid, $product->id, '041026-0007', 7);
        $sale['notes'] = 'Mesa 4';
        $sale['preparation_status'] = 'preparing';
        $sale['items'][0]['item_note'] = 'Sin azúcar';
        $sale['items'][0]['allergen_flags'] = ['gluten', 'lactosa'];

        $this->actingAs($cashier)
            ->postJson('/api/sales/sync', ['sales' => [$sale]])
            ->assertStatus(200)
            ->assertJsonPath('data.synced.0', $uuid);

        $this->assertDatabaseHas('sales', [
            'id' => $uuid,
            'order_number' => '041026-0007',
            'daily_sequence' => 7,
            'notes' => 'Mesa 4',
            'preparation_status' => 'preparing',
        ]);

        $item = \App\Models\SaleItem::where('sale_id', $uuid)->first();
        $this->assertSame('Sin azúcar', $item->item_note);
        $this->assertSame(['gluten', 'lactosa'], $item->allergen_flags);
    }

    /**
     * Prueba que si dos dispositivos envían el mismo número de ticket, el segundo recibe uno nuevo.
     */
    public function test_it_reassigns_order_number_on_collision(): void
    {
        $cashier = User::factory()->create();
        $product = $this->createProduct();
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();

        $this->actingAs($cashier)->postJson('/api/sales/sync', ['sales' => [
            $this->salePayload($first, $product->id, '041026-0001', 1),
            $this->salePayload($second, $product->id, '041026-0001', 1),
        ]])->assertStatus(200);

        $this->assertDatabaseHas('sales', ['id' => $first, 'order_number' => '041026-0001']);
        $this->assertDatabaseHas('sales', ['id' => $second, 'order_number' => '041026-0002', 'daily_sequence' => 2]);
    }

    private function createProduct(): Product
    {
        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);

        return Product::create([
            'name' => 'Bowl Grande',
            'price' => 35,
            'category_id' => $category->id,
            'printer_target' => 'kitchen',
        ]);
    }

    private function salePayload(string $uuid, int $productId, string $orderNumber, int $dailySequence): array
    {
        return [
            'id' => $uuid,
            'order_number' => $orderNumber,
            'daily_sequence' => $dailySequence,
            'subtotal' => 35,
            'total_amount' => 35,
            'created_at' => '2026-10-04T15:00:00Z',
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'unit_price' => 35,
                'subtotal' => 35,
            ]],
            'payments' => [['method' => 'cash', 'amount' => 35]],
        ];
    }
}
