<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:100'],
            'section'     => ['required', 'string', 'in:cost_of_sales,operating,financial,taxes'],
            'group_name'  => ['nullable', 'string', 'max:100'],
            'sort_order'  => ['nullable', 'integer'],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'El nombre de la categoría es obligatorio.',
            'section.required' => 'La sección contable es obligatoria.',
            'section.in'       => 'La sección contable no es válida.',
        ];
    }
}
