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
            'quantity_changed' => ['required_without:counted_stock', 'nullable', 'numeric', 'not_in:0'],
            // Conteo físico: lo que hay; el sistema calcula la diferencia con su stock del momento
            'counted_stock' => ['nullable', 'numeric', 'min:0', 'prohibited_unless:type,adjustment'],
            'type' => ['required', 'in:waste,restock,adjustment'],
            'waste_category' => ['nullable', 'string', 'required_if:type,waste', 'in:expired,damaged,spillage'],
            'notes' => ['nullable', 'string'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'required_if:type,restock'],
        ];
    }

    /** La merma saca stock y el ingreso lo suma: un signo equivocado inflaría o vaciaría el inventario. */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('quantity_changed') || !$this->filled('quantity_changed')) {
                return;
            }
            $quantity = (float) $this->input('quantity_changed');
            if ($this->input('type') === 'waste' && $quantity > 0) {
                $validator->errors()->add('quantity_changed', 'Una merma resta stock: la cantidad debe ser negativa.');
            }
            if ($this->input('type') === 'restock' && $quantity < 0) {
                $validator->errors()->add('quantity_changed', 'Un ingreso suma stock: la cantidad debe ser positiva.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'ingredient_id.required' => 'El insumo es obligatorio.',
            'ingredient_id.exists' => 'El insumo seleccionado no es válido.',
            'quantity_changed.required_without' => 'La cantidad es obligatoria.',
            'counted_stock.numeric' => 'La cantidad contada debe ser un número.',
            'counted_stock.min' => 'La cantidad contada no puede ser negativa.',
            'counted_stock.prohibited_unless' => 'El conteo solo se usa en ajustes.',
            'quantity_changed.numeric' => 'La cantidad debe ser un número.',
            'quantity_changed.not_in' => 'La cantidad no puede ser cero.',
            'type.required' => 'El tipo de movimiento es obligatorio.',
            'type.in' => 'El tipo de movimiento seleccionado no es válido.',
            'waste_category.in' => 'El motivo de la merma no es válido.',
            'waste_category.required_if' => 'La categoría de merma es obligatoria cuando el movimiento es una merma.',
            'unit_cost.numeric' => 'El costo unitario debe ser un número.',
            'unit_cost.min' => 'El costo unitario no puede ser negativo.',
            'unit_cost.required_if' => 'El costo unitario es obligatorio para un ingreso de compra.',
        ];
    }
}
