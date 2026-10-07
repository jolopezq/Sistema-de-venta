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
    Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
    Route::get('/ingredients/{ingredient}/movements', [InventoryMovementController::class, 'index']);
    Route::get('/loyalty-config', [LoyaltyConfigController::class, 'show']);

    // Recursos — escritura de catálogo, recetas e inventario (admin y super_admin)
    Route::middleware(['admin'])->group(function () {
        Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
        Route::apiResource('ingredient-categories', \App\Http\Controllers\IngredientCategoryController::class)->except(['index', 'show']);
        Route::post('option-groups/{option_group}/attach-products', [\App\Http\Controllers\OptionGroupController::class, 'attachProducts']);
        Route::post('option-groups/{option_group}/reorder-options', [\App\Http\Controllers\OptionGroupController::class, 'reorderOptions']);
        Route::apiResource('option-groups', \App\Http\Controllers\OptionGroupController::class)->except(['index', 'show']);
        Route::apiResource('options', \App\Http\Controllers\OptionController::class)->except(['index', 'show']);
        Route::apiResource('products', ProductController::class)->except(['index', 'show']);
        // Tarifario PedidosYa: precios de plataforma y disponibilidad en lote
        Route::put('/admin/pricing/pedidosya', [\App\Http\Controllers\PedidosYaPricingController::class, 'update']);
        Route::apiResource('ingredients', IngredientController::class)->except(['index', 'show']);
        Route::apiResource('customers', CustomerController::class)->except(['index', 'show']);

        Route::apiResource('recipes', RecipeController::class)->only(['store', 'destroy']);
        Route::post('/option-recipes', [OptionRecipeController::class, 'store']);
        Route::delete('/option-recipes/{optionRecipe}', [OptionRecipeController::class, 'destroy']);
        Route::post('/inventory/movements', [InventoryMovementController::class, 'store']);

        Route::put('/loyalty-config', [LoyaltyConfigController::class, 'update']);
        Route::post('/sales/{sale}/void', [SaleController::class, 'voidSale']);
    });

    // Ventas y caja (cocina no tiene acceso)
    Route::middleware(['role:super_admin,admin,cashier'])->group(function () {
        Route::post('/sales/sync', [SaleController::class, 'sync']);
        Route::apiResource('sales', SaleController::class)->only(['index', 'show']);

        // Sesiones de Caja / Turno y Arqueo (la propiedad de la sesión se valida en el controlador)
        Route::get('/cash-sessions/active', [CashRegisterSessionController::class, 'active']);
        Route::post('/cash-sessions/{cashSession}/close', [CashRegisterSessionController::class, 'close']);
        Route::get('/cash-sessions/{cashSession}/report', [CashRegisterSessionController::class, 'report']);
        Route::post('/cash-sessions/{cashSession}/movements', [CashRegisterSessionController::class, 'recordMovement']);
        Route::get('/cash-sessions/{cashSession}/movements', [CashRegisterSessionController::class, 'movements']);
        Route::apiResource('cash-sessions', CashRegisterSessionController::class)->only(['index', 'store', 'show']);
    });
    
    // Cola de Pedidos (KDS Web & Android App)
    Route::get('/order-queue', [\App\Http\Controllers\OrderQueueController::class, 'index']);
    Route::patch('/order-queue/{sale}/status', [\App\Http\Controllers\OrderQueueController::class, 'updateStatus']);

    // KDS Mobile API Especializada (Android / Comandas / Realtime Stream)
    Route::prefix('kds')->group(function () {
        Route::get('/orders', [\App\Http\Controllers\KdsController::class, 'index']);
        Route::patch('/orders/{sale}/status', [\App\Http\Controllers\KdsController::class, 'updateStatus']);
        Route::get('/stats', [\App\Http\Controllers\KdsController::class, 'stats']);
        Route::get('/stream', [\App\Http\Controllers\KdsController::class, 'stream']);
    });

    // Gestión de permisos de roles y logs (Solo Super Admin)
    Route::middleware(['super_admin'])->group(function () {
        Route::get('/permissions', [\App\Http\Controllers\RolePermissionController::class, 'index']);
        Route::put('/permissions', [\App\Http\Controllers\RolePermissionController::class, 'update']);
        Route::apiResource('users', \App\Http\Controllers\UserController::class)->except(['show']);

        Route::get('/audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index']);
        Route::get('/audit-logs/export', [\App\Http\Controllers\AuditLogController::class, 'export']);
        Route::post('/system/backup/download', [\App\Http\Controllers\SystemBackupController::class, 'download'])->middleware('throttle:5,60');
        Route::post('/system/backup/restore', [\App\Http\Controllers\SystemBackupController::class, 'restore'])->middleware('throttle:5,60');
    });

    // Gestión manual / retroactiva de ventas (admin y super_admin)
    Route::middleware(['admin'])->group(function () {
        Route::post('/admin/sales', [SaleController::class, 'adminCreate']);
        Route::put('/admin/sales/{sale}', [SaleController::class, 'adminUpdate']);
    });

    // Gestión de usuarios: reset password (super_admin o admin)
    Route::middleware(['admin'])->group(function () {
        Route::post('/users/{id}/reset-password', [\App\Http\Controllers\UserController::class, 'resetPassword']);
    });
});
