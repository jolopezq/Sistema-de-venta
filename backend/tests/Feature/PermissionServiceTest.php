<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\Sale;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PermissionService::class);
    }

    public function test_super_admin_always_has_access_to_all_modules_and_levels(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        foreach (PermissionService::MODULES as $module) {
            $this->assertTrue($this->service->can($superAdmin, $module, 'read'));
            $this->assertTrue($this->service->can($superAdmin, $module, 'edit'));
        }
    }

    public function test_fallback_behavior_when_table_has_no_entry(): void
    {
        // Limpiamos la tabla para forzar el comportamiento defaults
        RolePermission::truncate();
        $this->service->clearCache();

        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $kitchen = User::factory()->create(['role' => 'kitchen']);

        // Admin defaults
        $this->assertTrue($this->service->can($admin, 'catalog', 'edit'));
        $this->assertTrue($this->service->can($admin, 'inventory', 'edit'));
        $this->assertTrue($this->service->can($admin, 'pos', 'edit'));
        $this->assertTrue($this->service->can($admin, 'voids', 'edit'));
        $this->assertTrue($this->service->can($admin, 'register_self', 'edit'));
        $this->assertTrue($this->service->can($admin, 'register_others', 'edit'));
        $this->assertTrue($this->service->can($admin, 'crm_config', 'edit'));
        $this->assertTrue($this->service->can($admin, 'delivery_ops', 'edit'));
        $this->assertTrue($this->service->can($admin, 'reports', 'read'));
        $this->assertFalse($this->service->can($admin, 'reports', 'edit'));
        $this->assertTrue($this->service->can($admin, 'crm_read', 'read'));
        $this->assertFalse($this->service->can($admin, 'users', 'read'));
        $this->assertFalse($this->service->can($admin, 'audit', 'read'));

        // Cashier defaults
        $this->assertTrue($this->service->can($cashier, 'pos', 'read'));
        $this->assertTrue($this->service->can($cashier, 'pos', 'edit'));
        $this->assertTrue($this->service->can($cashier, 'register_self', 'edit'));
        $this->assertTrue($this->service->can($cashier, 'delivery_ops', 'read'));
        $this->assertTrue($this->service->can($cashier, 'delivery_ops', 'edit'));
        $this->assertTrue($this->service->can($cashier, 'crm_read', 'read'));
        $this->assertFalse($this->service->can($cashier, 'crm_config', 'read'));
        $this->assertFalse($this->service->can($cashier, 'catalog', 'read'));
        $this->assertFalse($this->service->can($cashier, 'inventory', 'read'));
        $this->assertFalse($this->service->can($cashier, 'voids', 'read'));
        $this->assertFalse($this->service->can($cashier, 'register_others', 'read'));
        $this->assertFalse($this->service->can($cashier, 'users', 'read'));

        // Kitchen defaults
        $this->assertTrue($this->service->can($kitchen, 'delivery_ops', 'read'));
        $this->assertTrue($this->service->can($kitchen, 'delivery_ops', 'edit'));
        $this->assertFalse($this->service->can($kitchen, 'pos', 'read'));
        $this->assertFalse($this->service->can($kitchen, 'catalog', 'read'));
        $this->assertFalse($this->service->can($kitchen, 'inventory', 'read'));
        $this->assertFalse($this->service->can($kitchen, 'voids', 'read'));
        $this->assertFalse($this->service->can($kitchen, 'crm_read', 'read'));
    }

    public function test_dynamic_permission_overrides_fallback(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Set catalog to 'read' only for admin
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'module' => 'catalog'],
            ['access_level' => 'read']
        );
        $this->service->clearCache();

        $this->assertTrue($this->service->can($admin, 'catalog', 'read'));
        $this->assertFalse($this->service->can($admin, 'catalog', 'edit'));

        // Change catalog to 'none'
        RolePermission::where('role', 'admin')->where('module', 'catalog')->update(['access_level' => 'none']);
        $this->service->clearCache();

        $this->assertFalse($this->service->can($admin, 'catalog', 'read'));
        $this->assertFalse($this->service->can($admin, 'catalog', 'edit'));
    }

    public function test_middleware_blocks_access_when_permission_missing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Restringir catalogo a 'read' para admin
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'module' => 'catalog'],
            ['access_level' => 'read']
        );
        $this->service->clearCache();

        // Crear una categoría requiere perm:catalog,edit
        $response = $this->actingAs($admin)->postJson('/api/categories', [
            'name' => 'Categoría Test',
            'sort_order' => 1,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'No tienes permiso para acceder a este módulo.',
            'reason'  => 'permission_denied',
        ]);
    }

    public function test_super_admin_can_update_permissions_matrix(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $payload = [
            'permissions' => [
                ['role' => 'admin', 'module' => 'catalog', 'access_level' => 'edit'],
                ['role' => 'cashier', 'module' => 'delivery_ops', 'access_level' => 'read'],
                ['role' => 'kitchen', 'module' => 'delivery_ops', 'access_level' => 'read'],
            ],
        ];

        $response = $this->actingAs($superAdmin)->putJson('/api/permissions', $payload);

        $response->assertOk();
        $this->assertDatabaseHas('role_permissions', [
            'role' => 'admin',
            'module' => 'catalog',
            'access_level' => 'edit',
        ]);
    }

    public function test_cannot_update_super_admin_permissions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $payload = [
            'permissions' => [
                ['role' => 'super_admin', 'module' => 'catalog', 'access_level' => 'read'],
            ],
        ];

        $response = $this->actingAs($superAdmin)->putJson('/api/permissions', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['permissions.0.role']);
    }

    public function test_cannot_give_blocked_modules_to_kitchen_or_cashier(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        // Intento 1: cocina con pos
        $response = $this->actingAs($superAdmin)->putJson('/api/permissions', [
            'permissions' => [
                ['role' => 'kitchen', 'module' => 'pos', 'access_level' => 'read'],
            ],
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['permissions.0.access_level']);

        // Intento 2: cajero con users
        $response = $this->actingAs($superAdmin)->putJson('/api/permissions', [
            'permissions' => [
                ['role' => 'cashier', 'module' => 'users', 'access_level' => 'read'],
            ],
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['permissions.0.access_level']);
    }

    public function test_permissions_index_returns_permissions_modules_and_restrictions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($superAdmin)->getJson('/api/permissions');

        $response->assertOk();
        $response->assertJsonStructure([
            'permissions',
            'modules',
            'restrictions' => [
                'kitchen',
                'cashier',
                'admin',
            ],
            'ceilings',
            'defaults',
        ]);
    }

    // =========================================================================
    // NUEVOS TESTS AUDITORÍA V2 (PLAN_ROLES_PERMISOS.md §7.3)
    // =========================================================================

    /**
     * Test 1: Una fila escrita directo en la base de datos no puede superar el techo.
     */
    public function test_ceiling_caps_database_rows(): void
    {
        $kitchen = User::factory()->create(['role' => 'kitchen']);
        $cashier = User::factory()->create(['role' => 'cashier']);

        // Inserción directa en base de datos burlando validación
        DB::table('role_permissions')->upsert([
            ['role' => 'kitchen', 'module' => 'pos', 'access_level' => 'edit'],
            ['role' => 'cashier', 'module' => 'catalog', 'access_level' => 'edit'],
            ['role' => 'cashier', 'module' => 'crm_read', 'access_level' => 'edit'],
        ], ['role', 'module'], ['access_level']);

        $this->service->clearCache();

        // El techo inamovible de kitchen para pos es 'none'
        $this->assertSame('none', $this->service->level($kitchen, 'pos'));
        $this->assertFalse($this->service->can($kitchen, 'pos', 'read'));
        $this->assertFalse($this->service->can($kitchen, 'pos', 'edit'));

        // El techo inamovible de cashier para catalog es 'none'
        $this->assertSame('none', $this->service->level($cashier, 'catalog'));
        $this->assertFalse($this->service->can($cashier, 'catalog', 'read'));

        // El techo inamovible de cashier para crm_read es 'read' (no puede llegar a 'edit')
        $this->assertSame('read', $this->service->level($cashier, 'crm_read'));
        $this->assertTrue($this->service->can($cashier, 'crm_read', 'read'));
        $this->assertFalse($this->service->can($cashier, 'crm_read', 'edit'));
    }

    /**
     * Test 2: Módulo o nivel desconocido falla cerrado lanzando InvalidArgumentException.
     */
    public function test_unknown_module_or_level_fails_closed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(InvalidArgumentException::class);
        $this->service->can($admin, 'catalgo', 'read');
    }

    public function test_unknown_level_fails_closed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(InvalidArgumentException::class);
        $this->service->can($admin, 'pos', 'editar');
    }

    /**
     * Test 3: Todas las rutas con middleware perm: tienen módulo y nivel válidos.
     */
    public function test_every_perm_route_parameter_is_valid(): void
    {
        $routes = Route::getRoutes()->getRoutes();
        $permCount = 0;

        foreach ($routes as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'perm:')) {
                    $permCount++;
                    $params = explode(',', substr($middleware, 5));
                    $module = $params[0] ?? '';
                    $level = $params[1] ?? 'read';

                    $this->assertContains(
                        $module,
                        PermissionService::MODULES,
                        "Ruta '{$route->uri()}' tiene un módulo de permisos inválido: '{$module}'"
                    );

                    $this->assertArrayHasKey(
                        $level,
                        PermissionService::LEVEL_VALUES,
                        "Ruta '{$route->uri()}' tiene un nivel de permisos inválido: '{$level}'"
                    );
                }
            }
        }

        $this->assertGreaterThan(10, $permCount, 'Se deben haber verificado las rutas protegidas con perm:');
    }

    /**
     * Test 4: Verificación de congruencia entre techo y permisos por rol.
     */
    public function test_route_access_matches_ceiling(): void
    {
        $kitchen = User::factory()->create(['role' => 'kitchen']);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $admin = User::factory()->create(['role' => 'admin']);

        // Con los permisos por defecto (que igualan a los techos operativos):
        $this->assertSame('edit', $this->service->level($kitchen, 'delivery_ops'));
        $this->assertSame('none', $this->service->level($kitchen, 'pos'));

        $this->assertSame('edit', $this->service->level($cashier, 'pos'));
        $this->assertSame('edit', $this->service->level($cashier, 'register_self'));
        $this->assertSame('read', $this->service->level($cashier, 'crm_read'));
        $this->assertSame('none', $this->service->level($cashier, 'catalog'));

        $this->assertSame('edit', $this->service->level($admin, 'catalog'));
        $this->assertSame('edit', $this->service->level($admin, 'inventory'));
        $this->assertSame('none', $this->service->level($admin, 'users'));
    }

    /**
     * Test 5: La migración deja la base de datos exactamente igual a DEFAULTS.
     */
    public function test_migration_matches_defaults(): void
    {
        // Ejecutar el método up de la migración v2
        $migration = require database_path('migrations/2026_10_15_000001_update_role_permissions_to_match_reality.php');
        $migration->up();
        $this->service->clearCache();

        foreach (PermissionService::DEFAULTS as $role => $modules) {
            foreach ($modules as $mod => $expectedLevel) {
                $actual = DB::table('role_permissions')
                    ->where('role', $role)
                    ->where('module', $mod)
                    ->value('access_level');

                $this->assertSame(
                    $expectedLevel,
                    $actual,
                    "Discrepancia en migración para {$role}.{$mod}: esperado {$expectedLevel}, obtenido {$actual}"
                );
            }
        }
    }

    /**
     * Test 6: /login y /me devuelven effective_permissions para todos los módulos.
     */
    public function test_login_and_me_return_effective_permissions(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'email' => 'cashier@test.com',
            'password' => bcrypt('password123'),
        ]);

        $loginRes = $this->postJson('/api/login', [
            'email' => 'cashier@test.com',
            'password' => 'password123',
        ]);

        $loginRes->assertOk();
        $loginRes->assertJsonStructure([
            'access_token',
            'effective_permissions' => PermissionService::MODULES,
        ]);
        $this->assertSame('edit', $loginRes->json('effective_permissions.pos'));
        $this->assertSame('none', $loginRes->json('effective_permissions.catalog'));

        // Probar /me
        $meRes = $this->actingAs($cashier)->getJson('/api/me');
        $meRes->assertOk();
        $meRes->assertJsonStructure([
            'effective_permissions' => PermissionService::MODULES,
        ]);
        $this->assertSame('edit', $meRes->json('effective_permissions.pos'));

        // Probar /me con Super Admin
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $superMeRes = $this->actingAs($superAdmin)->getJson('/api/me');
        $superMeRes->assertOk();
        foreach (PermissionService::MODULES as $mod) {
            $this->assertSame('edit', $superMeRes->json("effective_permissions.{$mod}"));
        }
    }

    /**
     * Test 7: Nivel read no puede escribir (separación de verbos).
     */
    public function test_read_level_cannot_write(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $kitchen = User::factory()->create(['role' => 'kitchen']);

        // Rebajar pos a 'read' para cashier
        RolePermission::updateOrCreate(
            ['role' => 'cashier', 'module' => 'pos'],
            ['access_level' => 'read']
        );

        // Rebajar delivery_ops a 'read' para kitchen
        RolePermission::updateOrCreate(
            ['role' => 'kitchen', 'module' => 'delivery_ops'],
            ['access_level' => 'read']
        );

        $this->service->clearCache();

        // 1. Cajero con pos=read puede consultar sales (GET), pero POST /sales/sync responde 403
        $getSalesRes = $this->actingAs($cashier)->getJson('/api/sales');
        $getSalesRes->assertOk();

        $postSyncRes = $this->actingAs($cashier)->postJson('/api/sales/sync', [
            'sales' => [],
        ]);
        $postSyncRes->assertStatus(403);
        $postSyncRes->assertJson(['reason' => 'permission_denied']);

        // 2. Cocina con delivery_ops=read puede consultar /order-queue (GET), pero PATCH status responde 403
        $getQueueRes = $this->actingAs($kitchen)->getJson('/api/order-queue');
        $getQueueRes->assertOk();

        $sale = Sale::create([
            'id' => (string) Str::uuid(),
            'cashier_id' => $kitchen->id,
            'subtotal' => 25.00,
            'total_amount' => 25.00,
            'status' => 'completed',
            'preparation_status' => 'received',
            'source' => 'pos',
        ]);

        $patchStatusRes = $this->actingAs($kitchen)->patchJson("/api/order-queue/{$sale->id}/status", [
            'status' => 'ready',
        ]);
        $patchStatusRes->assertStatus(403);
        $patchStatusRes->assertJson(['reason' => 'permission_denied']);
    }

    /**
     * Test 8: El guardado es atómico (transacción) y rechaza pares repetidos.
     */
    public function test_update_is_atomic_and_rejects_duplicates(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        // Envío con pares repetidos en el mismo lote
        $payloadWithDupes = [
            'permissions' => [
                ['role' => 'admin', 'module' => 'catalog', 'access_level' => 'none'],
                ['role' => 'admin', 'module' => 'catalog', 'access_level' => 'edit'],
            ],
        ];

        $response = $this->actingAs($superAdmin)->putJson('/api/permissions', $payloadWithDupes);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['permissions.1.module']);

        // Verificar que no se guardó nada nuevo por la falla de validación previa
        $this->assertDatabaseMissing('role_permissions', [
            'role' => 'admin',
            'module' => 'catalog',
            'access_level' => 'none',
        ]);
    }

    /**
     * Test 9: Denegación de rutas cuando el módulo está en 'none'.
     */
    public function test_route_level_denials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Admin con catalog = none y voids = none
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'module' => 'catalog'],
            ['access_level' => 'none']
        );
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'module' => 'voids'],
            ['access_level' => 'none']
        );
        $this->service->clearCache();

        // 1. Intento de crear categoría -> 403
        $prodRes = $this->actingAs($admin)->postJson('/api/categories', [
            'name' => 'Categoría Denegada',
            'sort_order' => 1,
        ]);
        $prodRes->assertStatus(403);
        $prodRes->assertJson(['reason' => 'permission_denied']);

        // 2. Intento de anular venta -> 403
        $sale = Sale::create([
            'id' => (string) Str::uuid(),
            'cashier_id' => $admin->id,
            'subtotal' => 20.00,
            'total_amount' => 20.00,
            'status' => 'completed',
            'source' => 'pos',
        ]);
        $voidRes = $this->actingAs($admin)->postJson("/api/sales/{$sale->id}/void", [
            'reason' => 'Error de digitación',
        ]);
        $voidRes->assertStatus(403);
        $voidRes->assertJson(['reason' => 'permission_denied']);
    }

    /**
     * Test 10: Validación de niveles permitidos en RolePermissionRequest (Fase 1).
     */
    public function test_permissions_request_validates_allowed_levels_and_array_type(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        // 1. permissions no es un array -> 422 (no 500)
        $resNonArray = $this->actingAs($superAdmin)->putJson('/api/permissions', [
            'permissions' => 'string_invalido',
        ]);
        $resNonArray->assertStatus(422);
        $resNonArray->assertJsonValidationErrors(['permissions']);

        // 2. inventory = read para admin es rechazado porque inventory solo admite ['none', 'edit']
        $resInvalidLevel = $this->actingAs($superAdmin)->putJson('/api/permissions', [
            'permissions' => [
                ['role' => 'admin', 'module' => 'inventory', 'access_level' => 'read'],
            ],
        ]);
        $resInvalidLevel->assertStatus(422);
        $resInvalidLevel->assertJsonValidationErrors(['permissions.0.access_level']);

        // 3. inventory = edit para admin es permitido
        $resValid = $this->actingAs($superAdmin)->putJson('/api/permissions', [
            'permissions' => [
                ['role' => 'admin', 'module' => 'inventory', 'access_level' => 'edit'],
            ],
        ]);
        $resValid->assertStatus(200);
    }

    /**
     * Test 11: Normalización en level() cuando existe un nivel no válido en base de datos.
     */
    public function test_level_normalizes_invalid_configured_levels(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Simular que en la BD quedó guardado inventory = read (módulo que solo soporta none o edit)
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'module' => 'inventory'],
            ['access_level' => 'read']
        );
        $this->service->clearCache();

        // Debe normalizarse al mayor nivel válido <= read, que es 'none'
        $this->assertSame('none', $this->service->level($admin, 'inventory'));
        $this->assertFalse($this->service->can($admin, 'inventory', 'read'));
        $this->assertFalse($this->service->can($admin, 'inventory', 'edit'));

        // Simular reports = edit (módulo que solo soporta none o read)
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'module' => 'reports'],
            ['access_level' => 'edit']
        );
        $this->service->clearCache();

        // Debe normalizarse al mayor nivel válido <= edit, que es 'read'
        $this->assertSame('read', $this->service->level($admin, 'reports'));
        $this->assertTrue($this->service->can($admin, 'reports', 'read'));
        $this->assertFalse($this->service->can($admin, 'reports', 'edit'));
    }

    /**
     * Test 12: GET /permissions devuelve la estructura 'levels' con los niveles soportados por rol.
     */
    public function test_index_returns_levels_structure(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($superAdmin)->getJson('/api/permissions');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'permissions',
            'modules',
            'restrictions',
            'ceilings',
            'defaults',
            'levels' => [
                'kitchen',
                'cashier',
                'admin',
            ],
        ]);

        // Verificar niveles de inventario para admin
        $this->assertEquals(['none', 'edit'], $response->json('levels.admin.inventory'));
        // Para cajero, como su techo en inventario es none:
        $this->assertEquals(['none'], $response->json('levels.cashier.inventory'));
        // crm_read para admin solo soporta none y read:
        $this->assertEquals(['none', 'read'], $response->json('levels.admin.crm_read'));
    }

    /**
     * Test 13: Permiso register_others = read permite ver cajas ajenas pero no modificarlas/cerrarlas.
     */
    public function test_register_others_read_allows_viewing_but_not_closing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create(['role' => 'cashier']);

        // Sesión abierta del cajero
        $cashierSession = CashRegisterSession::create([
            'cashier_id' => $cashier->id,
            'opening_amount' => 100.00,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Configurar admin con register_others = read
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'module' => 'register_others'],
            ['access_level' => 'read']
        );
        $this->service->clearCache();

        // 1. Admin puede ver el reporte de la caja ajena
        $reportRes = $this->actingAs($admin)->getJson("/api/cash-sessions/{$cashierSession->id}/report");
        $reportRes->assertStatus(200);

        // 2. Admin puede ver los movimientos de la caja ajena
        $movementsRes = $this->actingAs($admin)->getJson("/api/cash-sessions/{$cashierSession->id}/movements");
        $movementsRes->assertStatus(200);

        // 3. Admin puede ver el detalle (show) de la caja ajena
        $showRes = $this->actingAs($admin)->getJson("/api/cash-sessions/{$cashierSession->id}");
        $showRes->assertStatus(200);

        // 4. Admin NO puede cerrar la caja ajena con solo 'read' -> 403
        $closeRes = $this->actingAs($admin)->postJson("/api/cash-sessions/{$cashierSession->id}/close", [
            'actual_closing' => 100.00,
            'cash_withdrawn' => 0.00,
            'cash_left_for_next' => 100.00,
        ]);
        $closeRes->assertStatus(403);

        // 5. Con register_others = edit, el admin sí puede cerrar la caja ajena
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'module' => 'register_others'],
            ['access_level' => 'edit']
        );
        $this->service->clearCache();

        $closeResEdit = $this->actingAs($admin)->postJson("/api/cash-sessions/{$cashierSession->id}/close", [
            'actual_closing' => 100.00,
            'cash_withdrawn' => 0.00,
            'cash_left_for_next' => 100.00,
        ]);
        $closeResEdit->assertStatus(200);
        $this->assertEquals('closed', $cashierSession->fresh()->status);
    }
}
