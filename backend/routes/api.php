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
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\OptionRecipeController;

/*
|--------------------------------------------------------------------------
| API Routes — Ohana Acai V3
|--------------------------------------------------------------------------
*/

// --- Rutas Públicas ---
Route::post('/login', [AuthController::class, 'login']);

// --- Rutas Protegidas ---
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    
    // Catálogo offline-first
    Route::get('/catalog', [CatalogController::class, 'index']);

    // Recursos
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('ingredient-categories', \App\Http\Controllers\IngredientCategoryController::class);
    Route::post('option-groups/{option_group}/attach-products', [\App\Http\Controllers\OptionGroupController::class, 'attachProducts']);
    Route::apiResource('option-groups', \App\Http\Controllers\OptionGroupController::class);
    Route::apiResource('options', \App\Http\Controllers\OptionController::class);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('ingredients', IngredientController::class);
    Route::apiResource('customers', CustomerController::class);
    
    // Recetas e Inventario
    Route::apiResource('recipes', RecipeController::class)->only(['store', 'destroy']);
    Route::post('/option-recipes', [OptionRecipeController::class, 'store']);
    Route::delete('/option-recipes/{optionRecipe}', [OptionRecipeController::class, 'destroy']);
    Route::post('/inventory/movements', [InventoryMovementController::class, 'store']);
    Route::get('/ingredients/{ingredient}/movements', [InventoryMovementController::class, 'index']);
    
    // Ventas (Sincronización Offline)
    Route::post('/sales/sync', [SaleController::class, 'sync']);
    Route::post('/sales/{sale}/void', [SaleController::class, 'voidSale']);
    Route::apiResource('sales', SaleController::class)->only(['index', 'show']);
    
    Route::get('/loyalty-config', [LoyaltyConfigController::class, 'show']);
    Route::put('/loyalty-config', [LoyaltyConfigController::class, 'update']);

    // Gestión de permisos de roles y logs (Solo Super Admin)
    Route::middleware(['super_admin'])->group(function () {
        Route::get('/permissions', [\App\Http\Controllers\RolePermissionController::class, 'index']);
        Route::put('/permissions', [\App\Http\Controllers\RolePermissionController::class, 'update']);
        Route::apiResource('users', \App\Http\Controllers\UserController::class)->except(['show']);
        Route::get('/audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index']);
        Route::get('/audit-logs/export', [\App\Http\Controllers\AuditLogController::class, 'export']);
    });

    // Gestión de usuarios: reset password (super_admin o admin)
    Route::middleware(['admin'])->group(function () {
        Route::post('/users/{id}/reset-password', [\App\Http\Controllers\UserController::class, 'resetPassword']);
    });
});
