<?php

namespace App\Http\Controllers;

use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Models\Ingredient;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
    ) {}

    /**
     * Devuelve el catálogo completo (categorías, productos activos)
     * optimizado para la sincronización inicial de la caja (Offline-First).
     *
     * CORRECCIÓN: Los productos se cargan con `with('category')` mediante
     * el repositorio para evitar el problema N+1 al serializar ProductResource.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'categories'  => CategoryResource::collection($this->categories->allOrdered()),
            'products'    => ProductResource::collection($this->products->allActive()),
            'ingredients' => Ingredient::select('id', 'name', 'unit', 'current_stock')->get(),
            // TODO: Agregar 'customers' cuando el módulo CRM esté implementado
        ]);
    }
}
