<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    /**
     * Devuelve el catálogo completo (categorías, productos activos) 
     * optimizado para la sincronización inicial de la caja (Offline-First).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'categories' => CategoryResource::collection(Category::orderBy('sort_order')->get()),
            'products' => ProductResource::collection(Product::where('is_active', true)->get()),
            // TODO: Agregar 'customers' cuando el módulo CRM esté implementado
        ]);
    }
}
