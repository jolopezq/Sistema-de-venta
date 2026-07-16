<?php

namespace App\Http\Controllers;

use App\Repositories\ProductRepository;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductRepository $products
    ) {}

    /**
     * Lista paginada de todos los productos.
     * Usar paginación evita traer toda la tabla en producción.
     */
    public function index(): AnonymousResourceCollection
    {
        return ProductResource::collection($this->products->paginated());
    }

    /**
     * Crea un nuevo producto.
     */
    public function store(ProductRequest $request): ProductResource
    {
        $product = $this->products->create($request->validated());
        return new ProductResource($product);
    }

    /**
     * Muestra un producto específico.
     */
    public function show(int $id): ProductResource
    {
        return new ProductResource($this->products->findWithRecipes($id));
    }

    /**
     * Actualiza un producto existente.
     */
    public function update(ProductRequest $request, int $id): ProductResource
    {
        $product = $this->products->findWithRecipes($id);
        return new ProductResource($this->products->update($product, $request->validated()));
    }

    /**
     * Soft-delete de un producto (nunca DELETE físico).
     */
    public function destroy(int $id): Response
    {
        $this->products->delete($this->products->findWithRecipes($id));
        return response()->noContent();
    }
}
