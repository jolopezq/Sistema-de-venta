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
            'current_stock' => ['numeric', 'min:0'],
            'minimum_stock' => ['numeric', 'min:0'],
            'unit_cost' => ['numeric', 'min:0'],
            'weighted_avg_cost' => ['numeric', 'min:0'],
            'expiration_date' => ['nullable', 'date'],
            'min_shelf_date' => ['nullable', 'date', 'before_or_equal:expiration_date'],
        ];
    }
}
