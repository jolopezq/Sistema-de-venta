<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecurringExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:150'],
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'amount'              => ['required', 'numeric', 'min:0.01'],
            'frequency'           => ['required', 'string', 'in:monthly,bimonthly,quarterly,annual'],
            'day_of_month'        => ['nullable', 'integer', 'min:1', 'max:31'],
            'start_month'         => ['nullable', 'date_format:Y-m'],
            'payment_method'      => ['nullable', 'string', 'in:cash,qr,card,bank_transfer'],
            'is_active'           => ['nullable', 'boolean'],
            'notes'               => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                => 'El concepto del gasto recurrente es obligatorio.',
            'expense_category_id.required' => 'La categoría es obligatoria.',
            'expense_category_id.exists'   => 'La categoría seleccionada no existe.',
            'amount.required'              => 'El monto es obligatorio.',
            'amount.min'                   => 'El monto debe ser mayor a 0.',
            'frequency.required'           => 'La frecuencia es obligatoria.',
            'start_month.date_format'      => 'El mes de inicio debe tener el formato AAAA-MM.',
        ];
    }
}
