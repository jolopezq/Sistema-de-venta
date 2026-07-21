<?php

namespace App\Repositories;

use App\Models\IngredientCategory;
use Illuminate\Database\Eloquent\Collection;

class IngredientCategoryRepository
{
    public function all(): Collection
    {
        return IngredientCategory::all();
    }

    public function create(array $data): IngredientCategory
    {
        return IngredientCategory::create($data);
    }

    public function update(IngredientCategory $category, array $data): IngredientCategory
    {
        $category->update($data);
        return $category->fresh();
    }

    /**
     * Soft-delete de una categoría.
     * Se renombra la categoría para liberar el nombre (Unique Constraint)
     * y permitir que se pueda volver a crear una con el mismo nombre.
     */
    public function delete(IngredientCategory $category): void
    {
        // Añadimos un sufijo para liberar el nombre original
        $category->name = $category->name . '_deleted_' . time();
        $category->save();
        
        $category->delete();
    }
}
