<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecipeRequest extends FormRequest
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
            'product_id' => ['required', 'exists:products,id'],
            'ingredient_id' => ['required', 'exists:ingredients,id'],
            'quantity_required' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'El producto es obligatorio.',
            'product_id.exists' => 'El producto seleccionado no es válido.',
            'ingredient_id.required' => 'El insumo es obligatorio.',
            'ingredient_id.exists' => 'El insumo seleccionado no es válido.',
            'quantity_required.required' => 'La cantidad es obligatoria.',
            'quantity_required.numeric' => 'La cantidad debe ser un número.',
            'quantity_required.min' => 'La cantidad no puede ser menor a 0.001.',
        ];
    }
}
