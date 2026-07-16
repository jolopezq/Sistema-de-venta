<?php

namespace App\Repositories;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Repositorio de Productos.
 *
 * Centraliza TODAS las consultas a la tabla `products` para mantener
 * los controladores limpios (solo lógica HTTP) y facilitar el testing.
 *
 * Patrón: Repository Pattern (PHP The Right Way / buenas-practicas.md §5)
 */
class ProductRepository
{
    /**
     * Todos los productos activos, con su categoría cargada (eager load).
     * Evita el problema N+1 al serializar la relación en ProductResource.
     */
    public function allActive(): Collection
    {
        return Product::active()->with('category')->get();
    }

    /**
     * Lista paginada de todos los productos (activos e inactivos).
     * Usada en el panel administrativo. Evita `Product::all()` sin límite.
     *
     * @param int $perPage Elementos por página (default: 20).
     */
    public function paginated(int $perPage = 20): LengthAwarePaginator
    {
        return Product::with('category')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Busca un producto con sus recetas e insumos cargados.
     * Necesario para calcular el descuento de stock al sincronizar una venta.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findWithRecipes(int $id): Product
    {
        return Product::with('recipes.ingredient')->findOrFail($id);
    }

    /**
     * Crea un nuevo producto con los datos validados.
     */
    public function create(array $data): Product
    {
        return Product::create($data);
    }

    /**
     * Actualiza un producto existente.
     */
    public function update(Product $product, array $data): Product
    {
        $product->update($data);
        return $product->fresh();
    }

    /**
     * Soft-delete de un producto (nunca DELETE físico — buenas-practicas.md §5).
     */
    public function delete(Product $product): void
    {
        $product->delete();
    }
}
