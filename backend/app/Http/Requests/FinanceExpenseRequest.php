<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinanceExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date'                => ['required', 'date'],
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'supplier'            => ['nullable', 'string', 'max:150'],
            'amount'              => ['required', 'numeric', 'min:0.01'],
            'payment_method'      => ['required', 'string', 'in:cash,qr,card,bank_transfer'],
            'receipt_number'      => ['nullable', 'string', 'max:100'],
            'notes'               => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required'                => 'La fecha del gasto es obligatoria.',
            'expense_category_id.required' => 'La categoría es obligatoria.',
            'expense_category_id.exists'   => 'La categoría seleccionada no existe.',
            'amount.required'              => 'El monto es obligatorio.',
            'amount.min'                   => 'El monto debe ser mayor a 0.',
            'payment_method.required'      => 'El método de pago es obligatorio.',
        ];
    }
}
