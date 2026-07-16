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
}
