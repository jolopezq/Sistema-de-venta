<?php

namespace App\Http\Requests;

use App\Support\NameKey;
use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => NameKey::tidy($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:150'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'delivery_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'order_days'    => ['nullable', 'string', 'max:100'],
            'notes'         => ['nullable', 'string', 'max:1000'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $key = NameKey::make($this->input('name'));
            $current = $this->route('supplier');
            $currentId = $current instanceof \App\Models\Supplier ? $current->id : $current;
            $exists = \App\Models\Supplier::where('name_key', $key)
                ->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))
                ->exists();
            if ($key !== '' && $exists) {
                $validator->errors()->add('name', 'Ya hay un proveedor con ese nombre.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'El nombre del proveedor es obligatorio.',
            'delivery_days.integer' => 'Los días de entrega deben ser un número entero.',
        ];
    }
}
