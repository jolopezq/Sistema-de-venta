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
        return Product::active()->with(['category', 'recipes', 'optionGroups.options.recipes', 'excludedOptions'])->get();
    }

    /**
     * Lista paginada de todos los productos (activos e inactivos).
     * Usada en el panel administrativo. Evita `Product::all()` sin límite.
     *
     * @param int $perPage Elementos por página (default: 20).
     */
    public function paginated(int $perPage = 20): LengthAwarePaginator
    {
        return Product::with(['category', 'optionGroups', 'excludedOptions'])
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
        return Product::with(['recipes.ingredient', 'optionGroups.options.recipes.ingredient', 'excludedOptions'])->findOrFail($id);
    }

    /**
     * Crea un nuevo producto con los datos validados.
     */
    public function create(array $data): Product
    {
        $product = Product::create($data);
        $this->syncOptionGroups($product, $data['option_groups'] ?? []);
        $this->syncExcludedOptions($product, $data['excluded_options'] ?? []);
        return $product;
    }

    /**
     * Actualiza un producto existente.
     */
    public function update(Product $product, array $data): Product
    {
        $product->update($data);
        $this->syncOptionGroups($product, $data['option_groups'] ?? []);
        $this->syncExcludedOptions($product, $data['excluded_options'] ?? []);
        return $product->fresh();
    }

    /**
     * Sincroniza los grupos de opciones manteniendo el orden del frontend.
     */
    protected function syncOptionGroups(Product $product, array $optionGroups): void
    {
        if (empty($optionGroups)) {
            $product->optionGroups()->sync([]);
            return;
        }

        $syncData = [];
        foreach ($optionGroups as $index => $groupId) {
            $syncData[$groupId] = ['sort_order' => $index];
        }
        $product->optionGroups()->sync($syncData);
    }

    /**
     * Sincroniza las opciones que han sido excluidas específicamente para este producto.
     */
    protected function syncExcludedOptions(Product $product, array $excludedOptions): void
    {
        $product->excludedOptions()->sync($excludedOptions);
    }

    /**
     * Soft-delete de un producto (nunca DELETE físico — buenas-practicas.md §5).
     */
    public function delete(Product $product): void
    {
        $product->delete();
    }
}
