<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OptionGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Asumimos auth por middleware
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'min_selections' => ['required', 'integer', 'min:0'],
            'max_selections' => ['nullable', 'integer', 'gte:min_selections'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array'],
            'options.*.id' => ['nullable', 'exists:options,id'],
            'options.*.name' => ['required', 'string', 'max:255'],
            'options.*.additional_price' => ['required', 'numeric', 'min:0'],
            'options.*.delivery_price' => ['nullable', 'numeric', 'min:0'],
            'options.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
