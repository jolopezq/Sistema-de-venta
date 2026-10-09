<?php

namespace App\Http\Requests;

use App\Models\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Merma anotada desde Caja o Inventario (plan de inventario, F3): la cantidad llega positiva. */
class WasteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ingredient_id'  => ['required', Rule::exists('ingredients', 'id')->whereNull('deleted_at')->where('tracks_stock', true)],
            'quantity'       => ['required', 'numeric', 'gt:0', 'max:100000'],
            'waste_category' => ['required', 'string', Rule::in(array_keys(InventoryMovement::WASTE_CATEGORIES))],
            'notes'          => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'ingredient_id.required' => 'Elige el insumo.',
            'ingredient_id.exists'   => 'El insumo elegido no es válido.',
            'quantity.required'      => 'Indica la cantidad que se perdió.',
            'quantity.numeric'       => 'La cantidad debe ser un número.',
            'quantity.gt'            => 'La cantidad debe ser mayor a 0.',
            'quantity.max'           => 'La cantidad es demasiado grande.',
            'waste_category.required' => 'Elige el motivo de la merma.',
            'waste_category.in'      => 'El motivo de la merma no es válido.',
            'notes.max'              => 'La nota no puede pasar de 500 caracteres.',
        ];
    }
}
