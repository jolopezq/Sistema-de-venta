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
}
