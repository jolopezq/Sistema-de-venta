<?php

namespace App\Http\Requests;

use App\Models\Ingredient;
use App\Support\NameKey;
use Illuminate\Foundation\Http\FormRequest;

/** Compra con líneas: gasto de Finanzas + entradas de stock. */
class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('supplier')) {
            $this->merge(['supplier' => NameKey::tidy($this->input('supplier')) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'date'                  => ['required', 'date'],
            'expense_category_id'   => ['required', 'exists:expense_categories,id'],
            'supplier_id'           => ['nullable', 'exists:suppliers,id'],
            'supplier'              => ['nullable', 'string', 'max:150'],
            'payment_method'        => ['required', 'string', 'in:cash,qr,card,bank_transfer'],
            'receipt_number'        => ['nullable', 'string', 'max:100'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
            'items'                 => ['required', 'array', 'min:1', 'max:60'],
            'items.*.ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'items.*.description'   => ['nullable', 'string', 'max:150'],
            'items.*.quantity'      => ['required', 'numeric', 'gt:0'],
            'items.*.purchase_unit' => ['nullable', 'string', 'max:30'],
            'items.*.factor'        => ['nullable', 'numeric', 'gt:0'],
            'items.*.total'         => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('items', []) as $i => $item) {
                $ingredientId = $item['ingredient_id'] ?? null;
                if (!$ingredientId && trim((string) ($item['description'] ?? '')) === '') {
                    $validator->errors()->add("items.$i.description", 'Elige un insumo o escribe el concepto.');
                    continue;
                }
                // Los componentes de costeo no llevan stock: no se compran como insumo
                if ($ingredientId && !Ingredient::whereKey($ingredientId)->where('tracks_stock', true)->exists()) {
                    $validator->errors()->add("items.$i.ingredient_id", 'Este insumo no lleva stock; escribe el concepto sin elegir insumo.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'date.required'                => 'La fecha de la compra es obligatoria.',
            'expense_category_id.required' => 'La categoría es obligatoria.',
            'expense_category_id.exists'   => 'La categoría seleccionada no existe.',
            'payment_method.required'      => 'La forma de pago es obligatoria.',
            'items.required'               => 'Agrega al menos una línea a la compra.',
            'items.min'                    => 'Agrega al menos una línea a la compra.',
            'items.*.quantity.required'    => 'Indica la cantidad de cada línea.',
            'items.*.quantity.gt'          => 'La cantidad debe ser mayor a 0.',
            'items.*.total.required'        => 'Indica el importe de cada línea.',
            'items.*.total.min'            => 'El importe de cada línea debe ser mayor a 0.',
            'items.*.factor.gt'            => 'El factor debe ser mayor a 0.',
        ];
    }
}
