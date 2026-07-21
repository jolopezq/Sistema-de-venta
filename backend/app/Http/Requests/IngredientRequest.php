<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IngredientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'ingredient_category_id' => ['nullable', 'exists:ingredient_categories,id'],
            'type' => ['nullable', 'string', 'in:perecedero,no_perecedero,material_empaque,otros'],
            'current_stock' => ['numeric', 'min:0'],
            'minimum_stock' => ['numeric', 'min:0'],
            'unit_cost' => ['numeric', 'min:0'],
            'weighted_avg_cost' => ['numeric', 'min:0'],
            'expiration_date' => ['nullable', 'date'],
            'min_shelf_date' => ['nullable', 'date', 'before_or_equal:expiration_date'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del insumo es obligatorio.',
            'name.string' => 'El nombre debe ser texto.',
            'name.max' => 'El nombre no puede exceder 255 caracteres.',
            'unit.required' => 'La unidad de medida es obligatoria.',
            'ingredient_category_id.exists' => 'La categoría seleccionada no es válida.',
            'type.in' => 'El tipo de insumo no es válido.',
            'current_stock.numeric' => 'El stock debe ser un número.',
            'current_stock.min' => 'El stock no puede ser negativo.',
            'minimum_stock.numeric' => 'El stock mínimo debe ser un número.',
            'minimum_stock.min' => 'El stock mínimo no puede ser negativo.',
            'unit_cost.numeric' => 'El costo unitario debe ser un número.',
            'unit_cost.min' => 'El costo unitario no puede ser negativo.',
            'weighted_avg_cost.numeric' => 'El costo promedio debe ser un número.',
            'weighted_avg_cost.min' => 'El costo promedio no puede ser negativo.',
            'expiration_date.date' => 'La fecha de vencimiento no es válida.',
            'min_shelf_date.date' => 'La fecha mínima en estante no es válida.',
            'min_shelf_date.before_or_equal' => 'La fecha mínima debe ser anterior o igual a la de vencimiento.',
        ];
    }
}
