<?php

namespace App\Repositories;

use App\Models\Ingredient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Repositorio de Insumos (Ingredientes).
 *
 * Centraliza las consultas a la tabla `ingredients`.
 */
class IngredientRepository
{
    /**
     * Retorna todos los insumos ordenados por nombre.
     */
    public function all(array $filters = []): Collection
    {
        $query = Ingredient::with('category')->orderBy('name');

        if (!empty($filters['category_id'])) {
            $query->where('ingredient_category_id', $filters['category_id']);
        }

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        return $query->get();
    }

    /**
     * Lista paginada de todos los insumos.
     *
     * @param int $perPage
     */
    public function paginated(int $perPage = 20): LengthAwarePaginator
    {
        return Ingredient::orderBy('name')->paginate($perPage);
    }

    /**
     * Encuentra un insumo por ID.
     */
    public function find(int $id): Ingredient
    {
        return Ingredient::findOrFail($id);
    }

    /**
     * Encuentra un insumo con su historial de movimientos.
     */
    public function findWithMovements(int $id): Ingredient
    {
        return Ingredient::with(['inventoryMovements' => function ($query) {
            $query->latest();
        }, 'inventoryMovements.performedByUser'])->findOrFail($id);
    }

    /**
     * Crea un nuevo insumo.
     */
    public function create(array $data): Ingredient
    {
        return Ingredient::create($data);
    }

    /**
     * Actualiza un insumo existente.
     */
    public function update(Ingredient $ingredient, array $data): Ingredient
    {
        $ingredient->update($data);
        return $ingredient->fresh();
    }

    /**
     * Elimina lógicamente un insumo.
     */
    public function delete(Ingredient $ingredient): void
    {
        if ($ingredient->current_stock > 0) {
            throw new \Exception('No se puede eliminar un insumo con stock disponible. Ajuste el stock a cero primero.');
        }
        $ingredient->delete();
    }
}
