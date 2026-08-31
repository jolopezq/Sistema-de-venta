<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'option_group_id' => ['required', 'exists:option_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'additional_price' => ['required', 'numeric', 'min:0'],
            'delivery_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer'],
            'recipes' => ['nullable', 'array'],
            'recipes.*.ingredient_id' => ['required', 'exists:ingredients,id'],
            'recipes.*.quantity_delta' => ['required', 'numeric'],
        ];
    }
}
