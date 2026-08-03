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
    public function index(\Illuminate\Http\Request $request): AnonymousResourceCollection
    {
        $perPage = $request->input('per_page', 20);
        return ProductResource::collection($this->products->paginated((int) $perPage));
    }

    /**
     * Crea un nuevo producto.
     */
    public function store(ProductRequest $request): ProductResource
    {
        $data = $request->validated();
        if ($request->hasFile('image')) {
            $data['image_url'] = $request->file('image')->store('products', 'public');
        }
        $product = $this->products->create($data);
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
        $data = $request->validated();
        if ($request->hasFile('image')) {
            if ($product->image_url && \Illuminate\Support\Facades\Storage::disk('public')->exists($product->image_url)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image_url);
            }
            $data['image_url'] = $request->file('image')->store('products', 'public');
        }
        return new ProductResource($this->products->update($product, $data));
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
