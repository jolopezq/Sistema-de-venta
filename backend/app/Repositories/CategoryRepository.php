<?php

namespace App\Repositories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

/**
 * Repositorio de Categorías.
 *
 * Centraliza las consultas a `categories` y expone métodos con
 * nombres de negocio descriptivos en lugar de queries Eloquent sueltos.
 */
class CategoryRepository
{
    /**
     * Todas las categorías ordenadas por `sort_order` (para el menú del POS).
     * Incluye eager load de `products` si se necesita en el futuro sin cambiar el controlador.
     */
    public function allOrdered(): Collection
    {
        return Category::ordered()->get();
    }

    /**
     * Crea una nueva categoría.
     */
    public function create(array $data): Category
    {
        return Category::create($data);
    }

    /**
     * Actualiza una categoría existente.
     */
    public function update(Category $category, array $data): Category
    {
        $category->update($data);
        return $category->fresh();
    }

    /**
     * Soft-delete de una categoría (nunca DELETE físico).
     */
    public function delete(Category $category): void
    {
        $category->delete();
    }
}
