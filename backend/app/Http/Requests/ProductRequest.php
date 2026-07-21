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
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'vip_price' => ['nullable', 'numeric', 'min:0'],
            'is_weight_based' => ['boolean'],
            'price_per_gram' => ['nullable', 'numeric', 'min:0', 'required_if:is_weight_based,true'],
            'category_id' => ['required', 'exists:categories,id'],
            'printer_target' => ['required', 'in:kitchen,bar,none'],
            'is_active' => ['boolean'],
            'option_groups' => ['nullable', 'array'],
            'option_groups.*' => ['exists:option_groups,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio.',
            'name.string' => 'El nombre debe ser texto.',
            'name.max' => 'El nombre no puede exceder 255 caracteres.',
            'price.required' => 'El precio es obligatorio.',
            'price.numeric' => 'El precio debe ser un número.',
            'price.min' => 'El precio no puede ser negativo.',
            'vip_price.numeric' => 'El precio VIP debe ser un número.',
            'vip_price.min' => 'El precio VIP no puede ser negativo.',
            'is_weight_based.boolean' => 'La opción de venta por peso debe ser verdadera o falsa.',
            'price_per_gram.numeric' => 'El precio por gramo debe ser un número.',
            'price_per_gram.min' => 'El precio por gramo no puede ser negativo.',
            'price_per_gram.required_if' => 'El precio por gramo es obligatorio si se vende por peso.',
            'category_id.required' => 'La categoría es obligatoria.',
            'category_id.exists' => 'La categoría seleccionada no es válida.',
            'printer_target.required' => 'El destino de impresión es obligatorio.',
            'printer_target.in' => 'El destino de impresión seleccionado no es válido.',
            'is_active.boolean' => 'El estado del producto debe ser verdadero o falso.',
        ];
    }
}
