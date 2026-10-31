<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\LoyaltyConfigController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\CashRegisterSessionController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\OptionRecipeController;

/*
|--------------------------------------------------------------------------
| API Routes — Ohana Acai V3
|--------------------------------------------------------------------------
*/

// --- Rutas Públicas ---
Route::post('/login', [AuthController::class, 'login'])->name('login')->middleware('throttle:login');

// Recuperar la contraseña con un código enviado al correo (docs/PLAN_RECUPERAR_CONTRASENA.md)
Route::post('/password/code', [\App\Http\Controllers\PasswordResetController::class, 'sendCode'])->middleware('throttle:password-code');
Route::post('/password/verify', [\App\Http\Controllers\PasswordResetController::class, 'verifyCode'])->middleware('throttle:password-verify');
Route::post('/password/reset', [\App\Http\Controllers\PasswordResetController::class, 'reset'])->middleware('throttle:password-verify');
Route::get('/ping', function () { return response()->json(['status' => 'ok']); });

// --- Rutas Protegidas ---
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    
    // Catálogo offline-first
    Route::get('/catalog', [CatalogController::class, 'index']);

    // Recursos — lectura para cualquier usuario autenticado
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
    Route::apiResource('ingredient-categories', \App\Http\Controllers\IngredientCategoryController::class)->only(['index', 'show']);
    Route::apiResource('option-groups', \App\Http\Controllers\OptionGroupController::class)->only(['index', 'show']);
    Route::apiResource('options', \App\Http\Controllers\OptionController::class)->only(['index', 'show']);
    Route::apiResource('products', ProductController::class)->only(['index', 'show']);
    Route::apiResource('ingredients', IngredientController::class)->only(['index', 'show']);
    Route::apiResource('customers', CustomerController::class)->only(['index', 'show'])->middleware('perm:crm_read,read');
    Route::get('/loyalty-config', [LoyaltyConfigController::class, 'show']);

    // Recursos — escritura de catálogo, recetas e inventario (admin y super_admin)
    Route::middleware(['admin'])->group(function () {
        // Catálogo (productos, categorías, grupos de opciones, precios y recetas)
        Route::middleware(['perm:catalog,edit'])->group(function () {
            Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
            Route::post('option-groups/{option_group}/attach-products', [\App\Http\Controllers\OptionGroupController::class, 'attachProducts']);
            Route::post('option-groups/{option_group}/reorder-options', [\App\Http\Controllers\OptionGroupController::class, 'reorderOptions']);
            Route::apiResource('option-groups', \App\Http\Controllers\OptionGroupController::class)->except(['index', 'show']);
            Route::apiResource('options', \App\Http\Controllers\OptionController::class)->except(['index', 'show']);
            Route::apiResource('products', ProductController::class)->except(['index', 'show']);
            Route::put('/admin/pricing/pedidosya', [\App\Http\Controllers\PedidosYaPricingController::class, 'update']);
            Route::apiResource('recipes', RecipeController::class)->only(['store', 'destroy']);
            Route::post('/option-recipes', [OptionRecipeController::class, 'store']);
            Route::delete('/option-recipes/{optionRecipe}', [OptionRecipeController::class, 'destroy']);
        });

        // Inventario (insumos, categorías de insumos, movimientos, conteos, compras y proveedores)
        Route::middleware(['perm:inventory,edit'])->group(function () {
            Route::apiResource('ingredient-categories', \App\Http\Controllers\IngredientCategoryController::class)->except(['index', 'show']);
            Route::apiResource('ingredients', IngredientController::class)->except(['index', 'show']);
            Route::post('/inventory/movements', [InventoryMovementController::class, 'store']);
            Route::get('/ingredients/{ingredient}/movements', [InventoryMovementController::class, 'index']);
            Route::get('/inventory/reorder', [\App\Http\Controllers\InventoryReorderController::class, 'index']);
            Route::get('/inventory/reorder/summary', [\App\Http\Controllers\InventoryReorderController::class, 'summary']);
            Route::get('/inventory/counts/sheet', [\App\Http\Controllers\InventoryCountController::class, 'sheet']);
            Route::get('/inventory/counts', [\App\Http\Controllers\InventoryCountController::class, 'index']);
            Route::post('/inventory/counts', [\App\Http\Controllers\InventoryCountController::class, 'store']);
            Route::get('/inventory/counts/{inventoryCount}', [\App\Http\Controllers\InventoryCountController::class, 'show']);
            Route::get('/purchases/options', [\App\Http\Controllers\PurchaseController::class, 'options']);
            Route::apiResource('purchases', \App\Http\Controllers\PurchaseController::class)
                ->only(['show', 'store', 'update', 'destroy'])
                ->parameters(['purchases' => 'financeExpense']);
            Route::apiResource('suppliers', \App\Http\Controllers\SupplierController::class)->only(['index', 'store', 'update', 'destroy']);
        });

        // Reportes de inventario / mermas
        Route::middleware(['perm:reports,read'])->group(function () {
            Route::get('/inventory/waste/report', [\App\Http\Controllers\InventoryWasteController::class, 'report']);
        });

        // CRM y fidelización
        Route::middleware(['perm:crm_config,edit'])->group(function () {
            Route::apiResource('customers', CustomerController::class)->except(['index', 'show']);
            Route::put('/loyalty-config', [LoyaltyConfigController::class, 'update']);
        });

        // Anulaciones de ventas
        Route::post('/sales/{sale}/void', [SaleController::class, 'voidSale'])->middleware('perm:voids,edit');
    });

    // Ventas y caja (cocina no tiene acceso)
    Route::middleware(['role:super_admin,admin,cashier'])->group(function () {
        // Ventas POS: consulta de ventas (read) y sincronización/registro (edit)
        Route::apiResource('sales', SaleController::class)->only(['index', 'show'])->middleware('perm:pos,read');
        Route::post('/sales/sync', [SaleController::class, 'sync'])->middleware('perm:pos,edit');

        // Mermas en turno (lectura y registro)
        Route::get('/inventory/waste', [\App\Http\Controllers\InventoryWasteController::class, 'index'])->middleware('perm:register_self,read');
        Route::post('/inventory/waste', [\App\Http\Controllers\InventoryWasteController::class, 'store'])->middleware('perm:register_self,edit');

        // Sesiones de Caja / Turno y Arqueo: lectura (ver turno activo, reporte, movimientos, historial)
        Route::middleware(['perm:register_self,read'])->group(function () {
            Route::get('/cash-sessions/active', [CashRegisterSessionController::class, 'active']);
            Route::get('/cash-sessions/{cashSession}/report', [CashRegisterSessionController::class, 'report']);
            Route::get('/cash-sessions/{cashSession}/movements', [CashRegisterSessionController::class, 'movements']);
            Route::apiResource('cash-sessions', CashRegisterSessionController::class)->only(['index', 'show']);
        });

        // Sesiones de Caja / Turno y Arqueo: operaciones de escritura (abrir, cerrar, registrar movimientos)
        Route::middleware(['perm:register_self,edit'])->group(function () {
            Route::post('/cash-sessions', [CashRegisterSessionController::class, 'store']);
            Route::post('/cash-sessions/{cashSession}/close', [CashRegisterSessionController::class, 'close']);
            Route::post('/cash-sessions/{cashSession}/movements', [CashRegisterSessionController::class, 'recordMovement']);
        });
    });
    
    // Cola de Pedidos (KDS Web & Android App)
    Route::middleware(['perm:delivery_ops,read'])->group(function () {
        Route::get('/order-queue', [\App\Http\Controllers\OrderQueueController::class, 'index']);

        // KDS Mobile API Especializada (Android / Comandas / Realtime Stream)
        Route::prefix('kds')->group(function () {
            Route::get('/orders', [\App\Http\Controllers\KdsController::class, 'index']);
            Route::get('/stats', [\App\Http\Controllers\KdsController::class, 'stats']);
            Route::get('/stream', [\App\Http\Controllers\KdsController::class, 'stream']);
        });
    });

    Route::middleware(['perm:delivery_ops,edit'])->group(function () {
        Route::patch('/order-queue/{sale}/status', [\App\Http\Controllers\OrderQueueController::class, 'updateStatus']);
        Route::patch('/kds/orders/{sale}/status', [\App\Http\Controllers\KdsController::class, 'updateStatus']);
    });

    // Gestión de permisos de roles y logs (Solo Super Admin)
    Route::middleware(['super_admin'])->group(function () {
        Route::get('/permissions', [\App\Http\Controllers\RolePermissionController::class, 'index']);
        Route::put('/permissions', [\App\Http\Controllers\RolePermissionController::class, 'update']);
        Route::apiResource('users', \App\Http\Controllers\UserController::class)->except(['show'])->middleware('perm:users,edit');

        Route::middleware(['perm:audit,read'])->group(function () {
            Route::get('/audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index']);
            Route::get('/audit-logs/export', [\App\Http\Controllers\AuditLogController::class, 'export']);
        });
        Route::post('/system/backup/download', [\App\Http\Controllers\SystemBackupController::class, 'download'])->middleware('throttle:5,60');
        Route::post('/system/backup/restore', [\App\Http\Controllers\SystemBackupController::class, 'restore'])->middleware('throttle:5,60');

        // Módulo de Finanzas (Solo Super Admin)
        Route::prefix('finance')->group(function () {
            Route::get('/pnl', [\App\Http\Controllers\FinanceReportController::class, 'pnl']);
            Route::get('/break-even', [\App\Http\Controllers\FinanceReportController::class, 'breakEven']);
            Route::get('/inventory-indicators', [\App\Http\Controllers\InventoryIndicatorsController::class, 'index']);

            // Costos por receta y margen (componentes, recetas, ajustes de opciones y asistente de recetas base)
            Route::get('/costs', [\App\Http\Controllers\FinanceCostController::class, 'index']);
            Route::post('/costs/components', [\App\Http\Controllers\FinanceCostController::class, 'storeComponent']);
            Route::put('/costs/components/{ingredient}', [\App\Http\Controllers\FinanceCostController::class, 'updateComponent']);
            Route::put('/costs/components/{ingredient}/composition', [\App\Http\Controllers\FinanceCostController::class, 'updateComposition']);
            Route::put('/costs/products/{product}/recipe', [\App\Http\Controllers\FinanceCostController::class, 'updateProductRecipe']);
            Route::put('/costs/options/{option}/recipe', [\App\Http\Controllers\FinanceCostController::class, 'updateOptionRecipe']);
            Route::get('/costs/base-recipes', [\App\Http\Controllers\FinanceCostController::class, 'baseRecipesPreview']);
            Route::post('/costs/base-recipes', [\App\Http\Controllers\FinanceCostController::class, 'applyBaseRecipes']);

            // Categorías de gastos
            Route::post('/categories', [\App\Http\Controllers\ExpenseCategoryController::class, 'store']);
            Route::put('/categories/{expenseCategory}', [\App\Http\Controllers\ExpenseCategoryController::class, 'update']);
            Route::patch('/categories/{expenseCategory}/toggle-active', [\App\Http\Controllers\ExpenseCategoryController::class, 'toggleActive']);

            // Compras y gastos pagados fuera de caja
            // (el parámetro se llama como en el controlador; con `{expense}` no se resolvía el modelo
            // y editar o eliminar respondía 200 sin cambiar nada)
            Route::get('/suppliers', [\App\Http\Controllers\FinanceExpenseController::class, 'suppliers']);
            Route::apiResource('expenses', \App\Http\Controllers\FinanceExpenseController::class)
                ->parameters(['expenses' => 'financeExpense']);

            // Egresos de caja del mes y su clasificación contable
            Route::get('/cash-movements', [\App\Http\Controllers\FinanceCashMovementController::class, 'index']);
            Route::patch('/cash-movements/{cashMovement}/category', [\App\Http\Controllers\FinanceCashMovementController::class, 'classify']);

            // Gastos fijos recurrentes: propuesta y generación por mes (antes del recurso para no chocar con {id})
            Route::get('/recurring-expenses/pending', [\App\Http\Controllers\RecurringExpenseController::class, 'pending']);
            Route::post('/recurring-expenses/generate', [\App\Http\Controllers\RecurringExpenseController::class, 'generate']);
            Route::apiResource('recurring-expenses', \App\Http\Controllers\RecurringExpenseController::class);
            Route::patch('/recurring-expenses/{recurringExpense}/toggle-active', [\App\Http\Controllers\RecurringExpenseController::class, 'toggleActive']);

            // Liquidaciones semanales de PedidosYa (importación y conciliación)
            Route::post('/pedidosya-settlements/preview', [\App\Http\Controllers\PedidosYaSettlementController::class, 'preview']);
            Route::get('/pedidosya-settlements/{settlement}/sales', [\App\Http\Controllers\PedidosYaSettlementController::class, 'salesPreview']);
            Route::post('/pedidosya-settlements/{settlement}/sales', [\App\Http\Controllers\PedidosYaSettlementController::class, 'syncSales']);
            Route::get('/pedidosya-settlements/{settlement}/report', [\App\Http\Controllers\PedidosYaSettlementController::class, 'report']);

            // Equivalencias de nombres PedidosYa ↔ productos y opciones del POS
            Route::get('/pedidosya-mappings', [\App\Http\Controllers\PedidosYaMappingController::class, 'index']);
            Route::post('/pedidosya-mappings', [\App\Http\Controllers\PedidosYaMappingController::class, 'store']);
            Route::delete('/pedidosya-mappings/{mapping}', [\App\Http\Controllers\PedidosYaMappingController::class, 'destroy']);
            Route::apiResource('pedidosya-settlements', \App\Http\Controllers\PedidosYaSettlementController::class)
                ->except(['update'])
                ->parameters(['pedidosya-settlements' => 'settlement']);
        });
    });

    // Categorías de gastos para clasificar egresos en turnos (cajeros, admin, super_admin)
    Route::get('/expense-categories', [\App\Http\Controllers\ExpenseCategoryController::class, 'index']);

    // Gestión manual / retroactiva de ventas (admin y super_admin)
    Route::middleware(['admin', 'perm:pos,edit'])->group(function () {
        Route::post('/admin/sales', [SaleController::class, 'adminCreate']);
        Route::put('/admin/sales/{sale}', [SaleController::class, 'adminUpdate']);
    });

    // Gestión de usuarios: reset password (super_admin o admin)
    Route::middleware(['admin'])->group(function () {
        Route::post('/users/{id}/reset-password', [\App\Http\Controllers\UserController::class, 'resetPassword']);
    });
});
