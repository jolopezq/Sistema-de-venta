<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Ohana Acai V3
|--------------------------------------------------------------------------
|
| Todas las rutas aquí están prefijadas con /api y protegidas por
| el middleware auth:sanctum (excepto login y rutas públicas).
|
| Estructura según buenas prácticas:
| - Controlador Limpio: solo recibe Request y retorna JsonResponse.
| - Validación: delegada a FormRequests dedicados.
| - Lógica: delegada a Services y Repositories.
|
*/

// --- Rutas Públicas (sin autenticación) ---
Route::post('/login', function () {
    // TODO: Implementar AuthController@login
    return response()->json(['message' => 'Endpoint de login pendiente de implementación'], 501);
});

// --- Rutas Protegidas ---
Route::middleware('auth:sanctum')->group(function () {

    // Información del usuario autenticado
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // TODO: Registrar rutas de recursos por módulo
    // Route::apiResource('categories', CategoryController::class);
    // Route::apiResource('products', ProductController::class);
    // Route::apiResource('ingredients', IngredientController::class);
    // Route::apiResource('sales', SaleController::class);
    // Route::apiResource('customers', CustomerController::class);
});
