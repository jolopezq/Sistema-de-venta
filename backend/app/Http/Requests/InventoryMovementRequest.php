<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryMovementRequest extends FormRequest
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
            'ingredient_id' => ['required', 'exists:ingredients,id'],
            'quantity_changed' => ['required', 'numeric', 'not_in:0'],
            'type' => ['required', 'in:waste,restock,adjustment'],
            'waste_category' => ['nullable', 'string', 'required_if:type,waste'],
            'notes' => ['nullable', 'string'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'required_if:type,restock'],
        ];
    }

    public function messages(): array
    {
        return [
            'ingredient_id.required' => 'El insumo es obligatorio.',
            'ingredient_id.exists' => 'El insumo seleccionado no es válido.',
            'quantity_changed.required' => 'La cantidad es obligatoria.',
            'quantity_changed.numeric' => 'La cantidad debe ser un número.',
            'quantity_changed.not_in' => 'La cantidad no puede ser cero.',
            'type.required' => 'El tipo de movimiento es obligatorio.',
            'type.in' => 'El tipo de movimiento seleccionado no es válido.',
            'waste_category.required_if' => 'La categoría de merma es obligatoria cuando el movimiento es una merma.',
            'unit_cost.numeric' => 'El costo unitario debe ser un número.',
            'unit_cost.min' => 'El costo unitario no puede ser negativo.',
            'unit_cost.required_if' => 'El costo unitario es obligatorio para un ingreso de compra.',
        ];
    }
}
