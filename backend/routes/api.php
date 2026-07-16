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
    Route::apiResource('products', ProductController::class);
    Route::apiResource('ingredients', IngredientController::class);
    Route::apiResource('customers', CustomerController::class);
    
    // Recetas e Inventario
    Route::apiResource('recipes', RecipeController::class)->only(['store', 'destroy']);
    Route::post('/inventory/movements', [InventoryMovementController::class, 'store']);
    
    // Ventas (Sincronización Offline)
    Route::post('/sales/sync', [SaleController::class, 'sync']);
    Route::apiResource('sales', SaleController::class)->only(['index', 'show']);
    
    Route::get('/loyalty-config', [LoyaltyConfigController::class, 'show']);
    Route::put('/loyalty-config', [LoyaltyConfigController::class, 'update']);

    // Gestión de usuarios (Solo Admin)
    Route::middleware(['admin'])->group(function () {
        Route::apiResource('users', \App\Http\Controllers\UserController::class);
    });
});
