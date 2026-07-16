<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
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
            'image_url' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'vip_price' => ['nullable', 'numeric', 'min:0'],
            'is_weight_based' => ['boolean'],
            'price_per_gram' => ['nullable', 'numeric', 'min:0', 'required_if:is_weight_based,true'],
            'category_id' => ['required', 'exists:categories,id'],
            'printer_target' => ['required', 'in:kitchen,bar,none'],
            'is_active' => ['boolean'],
        ];
    }
}
