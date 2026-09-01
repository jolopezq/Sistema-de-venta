<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isSuperAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'created_at'             => ['required', 'date', 'before_or_equal:now'],
            'cashier_id'             => ['nullable', 'exists:users,id'],
            'customer_id'            => ['nullable', 'exists:customers,id'],
            'subtotal'               => ['required', 'numeric', 'min:0'],
            'discount_amount'        => ['nullable', 'numeric', 'min:0'],
            'total_amount'           => ['required', 'numeric', 'min:0'],
            'is_takeaway'            => ['nullable', 'boolean'],
            'notes'                  => ['nullable', 'string', 'max:500'],
            'edit_reason'            => ['required', 'string', 'min:5', 'max:255'],

            // Items
            'items'                          => ['required', 'array', 'min:1'],
            'items.*.product_id'             => ['required', 'exists:products,id'],
            'items.*.quantity'               => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price'             => ['required', 'numeric', 'min:0'],
            'items.*.subtotal'               => ['required', 'numeric', 'min:0'],
            'items.*.is_takeaway'            => ['nullable', 'boolean'],
            'items.*.item_note'              => ['nullable', 'string', 'max:255'],
            'items.*.modifiers'              => ['nullable', 'array'],
            'items.*.modifiers.*.option_id'  => ['required_with:items.*.modifiers', 'integer', 'exists:options,id'],
            'items.*.modifiers.*.quantity'   => ['nullable', 'numeric', 'min:0.01'],

            // Payments
            'payments'               => ['required', 'array', 'min:1'],
            'payments.*.method'      => ['required', 'in:cash,qr,card'],
            'payments.*.amount'      => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'created_at.required' => 'La fecha de la venta es obligatoria.',
            'created_at.date' => 'La fecha ingresada no tiene un formato válido.',
            'created_at.before_or_equal' => 'La fecha no puede ser en el futuro.',
            'edit_reason.required' => 'Debes especificar un motivo/justificación para este registro o edición.',
            'edit_reason.min' => 'El motivo debe tener al menos 5 caracteres descriptivos.',
            'items.required' => 'Debes incluir al menos un producto en la venta.',
            'items.min' => 'Debes incluir al menos un producto en la venta.',
            'payments.required' => 'Debes registrar al menos un método de pago.',
            'payments.min' => 'Debes registrar al menos un método de pago.',
        ];
    }
}
