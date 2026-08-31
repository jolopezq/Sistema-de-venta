<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncSalesRequest extends FormRequest
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
            'sales' => ['required', 'array'],
            'sales.*.id' => ['required', 'uuid'],
            'sales.*.customer_id' => ['nullable', 'exists:customers,id'],
            'sales.*.subtotal' => ['required', 'numeric', 'min:0'],
            'sales.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'sales.*.total_amount' => ['required', 'numeric', 'min:0'],
            'sales.*.status' => ['nullable', 'in:completed,voided'],
            'sales.*.source' => ['nullable', 'in:pos,pedidosya'],
            'sales.*.is_takeaway' => ['nullable', 'boolean'],
            'sales.*.created_at' => ['nullable', 'date'],
            
            // Items
            'sales.*.items'                          => ['required', 'array', 'min:1'],
            'sales.*.items.*.product_id'             => ['required', 'exists:products,id'],
            'sales.*.items.*.quantity'               => ['required', 'numeric', 'min:0.01'],
            'sales.*.items.*.unit_price'             => ['required', 'numeric', 'min:0'],
            'sales.*.items.*.subtotal'               => ['required', 'numeric', 'min:0'],
            'sales.*.items.*.is_takeaway'            => ['nullable', 'boolean'],
            // El frontend envía 'modifiers'; el legado usa 'topping_modifications'. Ambos son aceptados.
            'sales.*.items.*.modifiers'              => ['nullable', 'array'],
            'sales.*.items.*.modifiers.*.option_id'  => ['required_with:sales.*.items.*.modifiers', 'integer'],
            'sales.*.items.*.modifiers.*.option_name'=> ['required_with:sales.*.items.*.modifiers', 'string'],
            'sales.*.items.*.modifiers.*.price'      => ['nullable', 'numeric', 'min:0'],
            'sales.*.items.*.topping_modifications'  => ['nullable', 'array'],

            // Payments
            'sales.*.payments' => ['required', 'array', 'min:1'],
            'sales.*.payments.*.method' => ['required', 'string'],
            'sales.*.payments.*.amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'sales.required' => 'Las ventas son obligatorias.',
            'sales.array' => 'Las ventas deben ser una lista.',
            'sales.*.id.required' => 'El ID de la venta es obligatorio.',
            'sales.*.id.uuid' => 'El ID de la venta no es válido.',
            'sales.*.customer_id.exists' => 'El cliente no es válido.',
            'sales.*.subtotal.required' => 'El subtotal es obligatorio.',
            'sales.*.subtotal.numeric' => 'El subtotal debe ser un número.',
            'sales.*.total_amount.required' => 'El monto total es obligatorio.',
            'sales.*.total_amount.numeric' => 'El monto total debe ser un número.',
            'sales.*.items.required' => 'Los ítems de la venta son obligatorios.',
            'sales.*.payments.required' => 'Los pagos de la venta son obligatorios.',
        ];
    }
}
