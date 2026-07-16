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
            'sales.*.created_at' => ['nullable', 'date'],
            
            // Items
            'sales.*.items' => ['required', 'array', 'min:1'],
            'sales.*.items.*.product_id' => ['required', 'exists:products,id'],
            'sales.*.items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'sales.*.items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'sales.*.items.*.subtotal' => ['required', 'numeric', 'min:0'],
            'sales.*.items.*.topping_modifications' => ['nullable', 'array'],

            // Payments
            'sales.*.payments' => ['required', 'array', 'min:1'],
            'sales.*.payments.*.method' => ['required', 'string'],
            'sales.*.payments.*.amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
