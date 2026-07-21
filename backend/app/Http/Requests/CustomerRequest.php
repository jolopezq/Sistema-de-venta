<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
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
            'ci_or_phone' => ['required', 'string', 'max:255', 'unique:customers,ci_or_phone,' . $this->route('customer')?->id],
            'name' => ['required', 'string', 'max:255'],
            'is_vip_pricing' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ci_or_phone.required' => 'El CI o teléfono es obligatorio.',
            'ci_or_phone.string' => 'El CI o teléfono debe ser texto.',
            'ci_or_phone.max' => 'El CI o teléfono no puede exceder 255 caracteres.',
            'ci_or_phone.unique' => 'Ya existe un cliente registrado con este CI o teléfono.',
            'name.required' => 'El nombre del cliente es obligatorio.',
            'name.string' => 'El nombre debe ser texto.',
            'name.max' => 'El nombre no puede exceder 255 caracteres.',
            'is_vip_pricing.boolean' => 'El valor de precio VIP debe ser verdadero o falso.',
        ];
    }
}
