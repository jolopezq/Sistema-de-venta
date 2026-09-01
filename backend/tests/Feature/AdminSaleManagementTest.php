<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\AuditLog;
use Carbon\Carbon;

class AdminSaleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $admin;
    private User $cashier;
    private Product $product1;
    private Product $product2;
    private Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);

        $category = Category::create(['name' => 'Bowls', 'sort_order' => 1]);

        $this->ingredient = Ingredient::create([
            'name' => 'Acai Puro',
            'unit' => 'kg',
            'current_stock' => 10.0,
        ]);

        $this->product1 = Product::create([
            'name' => 'Bowl Mediano',
            'price' => 30.0,
            'category_id' => $category->id,
        ]);
        Recipe::create([
            'product_id' => $this->product1->id,
            'ingredient_id' => $this->ingredient->id,
            'quantity_required' => 0.2, // 200g
        ]);

        $this->product2 = Product::create([
            'name' => 'Bowl Grande',
            'price' => 50.0,
            'category_id' => $category->id,
        ]);
        Recipe::create([
            'product_id' => $this->product2->id,
            'ingredient_id' => $this->ingredient->id,
            'quantity_required' => 0.4, // 400g
        ]);
    }

    public function test_super_admin_can_create_retroactive_sale(): void
    {
        $pastDate = Carbon::now()->subDays(3)->setTime(15, 30, 0);

        $payload = [
            'created_at' => $pastDate->toDateTimeString(),
            'cashier_id' => $this->cashier->id,
            'subtotal' => 60.0,
            'discount_amount' => 0.0,
            'total_amount' => 60.0,
            'edit_reason' => 'Venta manual en talonario por caída temporal de energía eléctrica',
            'notes' => 'Registro retroactivo',
            'items' => [
                [
                    'product_id' => $this->product1->id,
                    'quantity' => 2,
                    'unit_price' => 30.0,
                    'subtotal' => 60.0,
                ]
            ],
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 60.0,
                ]
            ]
        ];

        $response = $this->actingAs($this->superAdmin)->postJson('/api/admin/sales', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('sale.source', 'manual_retroactive');
        $response->assertJsonPath('sale.total_amount', '60.00');

        $expectedOrderPrefix = $pastDate->format('dmy');
        $this->assertStringStartsWith($expectedOrderPrefix, $response->json('sale.order_number'));

        // Verificar descuento de stock (10 - 2*0.2 = 9.6)
        $this->assertEquals(9.6, $this->ingredient->fresh()->current_stock);

        // Verificar log de auditoría
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->superAdmin->id,
            'action'  => 'admin_sale_create',
            'module'  => 'Sales',
        ]);
    }

    public function test_super_admin_can_edit_existing_sale_and_recalculate_stock(): void
    {
        // 1. Crear venta inicial (1x Bowl Mediano = -0.2kg)
        $pastDate = Carbon::now()->subDay()->setTime(12, 0, 0);

        $createPayload = [
            'created_at' => $pastDate->toDateTimeString(),
            'cashier_id' => $this->cashier->id,
            'subtotal' => 30.0,
            'discount_amount' => 0.0,
            'total_amount' => 30.0,
            'edit_reason' => 'Venta inicial',
            'items' => [
                [
                    'product_id' => $this->product1->id,
                    'quantity' => 1,
                    'unit_price' => 30.0,
                    'subtotal' => 30.0,
                ]
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 30.0]
            ]
        ];

        $createRes = $this->actingAs($this->superAdmin)->postJson('/api/admin/sales', $createPayload);
        $createRes->assertStatus(201);
        $saleId = $createRes->json('sale.id');

        $this->assertEquals(9.8, $this->ingredient->fresh()->current_stock);

        // 2. Super Admin edita la venta a 1x Bowl Grande (requiere 0.4kg en lugar de 0.2kg)
        $updatePayload = [
            'created_at' => $pastDate->toDateTimeString(),
            'cashier_id' => $this->cashier->id,
            'subtotal' => 50.0,
            'discount_amount' => 0.0,
            'total_amount' => 50.0,
            'edit_reason' => 'Cliente cambió a Bowl Grande pero el cajero marcó Mediano por error',
            'items' => [
                [
                    'product_id' => $this->product2->id,
                    'quantity' => 1,
                    'unit_price' => 50.0,
                    'subtotal' => 50.0,
                ]
            ],
            'payments' => [
                ['method' => 'qr', 'amount' => 50.0]
            ]
        ];

        $updateRes = $this->actingAs($this->superAdmin)->putJson("/api/admin/sales/{$saleId}", $updatePayload);

        $updateRes->assertStatus(200);
        $updateRes->assertJsonPath('sale.total_amount', '50.00');
        $updateRes->assertJsonPath('sale.edited_by', $this->superAdmin->id);

        // El stock debe ser: 10 - 0.4 = 9.6 kg
        $this->assertEquals(9.6, $this->ingredient->fresh()->current_stock);

        // Verificar movimiento de reversión y nuevo movimiento
        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $this->ingredient->id,
            'quantity_changed' => 0.2, // Reverso del producto1
            'type' => 'adjustment',
        ]);

        // Verificar log de auditoría
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->superAdmin->id,
            'action'  => 'admin_sale_edit',
            'module'  => 'Sales',
        ]);
    }

    public function test_cashier_and_admin_cannot_access_admin_sales_endpoints(): void
    {
        $payload = [
            'created_at' => now()->toDateTimeString(),
            'subtotal' => 30.0,
            'total_amount' => 30.0,
            'edit_reason' => 'Intento no autorizado',
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1, 'unit_price' => 30.0, 'subtotal' => 30.0]
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 30.0]
            ]
        ];

        // Cajero
        $resCashier = $this->actingAs($this->cashier)->postJson('/api/admin/sales', $payload);
        $resCashier->assertStatus(403);

        // Admin regular
        $resAdmin = $this->actingAs($this->admin)->postJson('/api/admin/sales', $payload);
        $resAdmin->assertStatus(403);
    }

    public function test_edit_reason_is_mandatory(): void
    {
        $payload = [
            'created_at' => now()->toDateTimeString(),
            'subtotal' => 30.0,
            'total_amount' => 30.0,
            // Falta edit_reason
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1, 'unit_price' => 30.0, 'subtotal' => 30.0]
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 30.0]
            ]
        ];

        $res = $this->actingAs($this->superAdmin)->postJson('/api/admin/sales', $payload);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['edit_reason']);
    }

    public function test_cannot_edit_voided_sale(): void
    {
        $sale = Sale::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'order_number' => '300826-0001',
            'daily_sequence' => 1,
            'cashier_id' => $this->cashier->id,
            'subtotal' => 30.0,
            'total_amount' => 30.0,
            'status' => 'voided',
            'void_reason' => 'Anulado',
            'created_at' => now()->subDay(),
        ]);

        $payload = [
            'created_at' => now()->subDay()->toDateTimeString(),
            'subtotal' => 30.0,
            'total_amount' => 30.0,
            'edit_reason' => 'Intentando editar venta anulada',
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1, 'unit_price' => 30.0, 'subtotal' => 30.0]
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 30.0]
            ]
        ];

        $res = $this->actingAs($this->superAdmin)->putJson("/api/admin/sales/{$sale->id}", $payload);
        $res->assertStatus(422);
    }
}
