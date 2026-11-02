<?php

namespace App\Http\Requests;

use App\Models\Sale;
use App\Services\DiscountPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Ventas de días pasados (Control diario): permiso por persona, además del rol admin de la ruta.
        return $this->user() && $this->user()->isAdmin()
            && app(\App\Services\PermissionService::class)->can($this->user(), 'sales_history', 'edit');
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
            'paper_ref'              => ['nullable', 'string', 'max:30'],
            'customer_id'            => ['nullable', 'exists:customers,id'],
            'subtotal'               => ['required', 'numeric', 'min:0'],
            'discount_amount'        => ['nullable', 'numeric'],
            'discount_reason'        => ['nullable', 'string', 'max:' . DiscountPolicy::REASON_MAX],
            'total_amount'           => ['required', 'numeric', 'min:0'],
            'source'                 => ['nullable', 'in:pos,pedidosya'],
            'is_takeaway'            => ['nullable', 'boolean'],
            'notes'                  => ['nullable', 'string', 'max:500'],
            'edit_reason'            => ['required', 'string', 'min:5', 'max:255'],

            // Items
            'items'                          => ['required', 'array', 'min:1'],
            'items.*.product_id'             => ['required', 'exists:products,id'],
            'items.*.quantity'               => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price'             => ['required', 'numeric', 'min:0'],
            'items.*.subtotal'               => ['required', 'numeric', 'min:0'],
            'items.*.weight_grams'           => ['nullable', 'numeric', 'min:0'],
            'items.*.is_takeaway'            => ['nullable', 'boolean'],
            'items.*.item_note'              => ['nullable', 'string', 'max:255'],
            'items.*.price_override_reason'  => ['nullable', 'string', 'max:255'],
            'items.*.modifiers'              => ['nullable', 'array'],
            'items.*.modifiers.*.option_id'  => ['required_with:items.*.modifiers', 'integer', 'exists:options,id'],
            'items.*.modifiers.*.quantity'   => ['nullable', 'numeric', 'min:0.01'],

            // Payments
            'payments'               => ['required', 'array', 'min:1'],
            'payments.*.method'      => ['required', 'in:cash,qr,card,delivery_platform'],
            // Bs 0 solo en una cortesía (total 0): se valida en withValidator
            'payments.*.amount'      => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Descuento manual (docs/PLAN_DESCUENTOS_CAJA.md §3.8): con descuento, el motivo es obligatorio; un descuento
     * nuevo o con otro monto exige el permiso «Descuentos en caja» y respeta el tope del rol. Aquí sí se rechaza:
     * es un formulario en línea, no una venta ya cobrada.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $total = (float) $this->input('total_amount', 0);
            foreach ((array) $this->input('payments', []) as $i => $payment) {
                if ($total > 0 && (float) ($payment['amount'] ?? 0) < 0.01) {
                    $validator->errors()->add("payments.{$i}.amount", 'Un pago no puede ser de Bs 0: solo una cortesía (total Bs 0) se registra así.');
                }
            }

            $discount = round((float) $this->input('discount_amount', 0), 2);
            if ($discount <= 0) {
                return;
            }

            $policy = app(DiscountPolicy::class);
            $reason = trim((string) $this->input('discount_reason', ''));
            if (mb_strlen($reason) < DiscountPolicy::REASON_MIN) {
                $validator->errors()->add('discount_reason', 'Escribe el motivo del descuento (mínimo ' . DiscountPolicy::REASON_MIN . ' caracteres).');
            }

            /** @var Sale|null $sale */
            $sale = $this->route('sale');
            $changed = !$sale || abs($discount - (float) $sale->discount_amount) >= 0.005;
            if (!$changed) {
                return;
            }

            if ($this->input('source') === 'pedidosya') {
                $validator->errors()->add('discount_amount', 'Los pedidos de PedidosYa no llevan descuento manual.');
                return;
            }
            if (!$policy->allowed($this->user())) {
                $validator->errors()->add('discount_amount', 'Necesitas el permiso «Descuentos en caja» para aplicar o cambiar un descuento.');
                return;
            }
            $max = $policy->maxAmount($this->user(), (float) $this->input('subtotal', 0));
            if ($discount - $max > 0.004) {
                $validator->errors()->add('discount_amount', sprintf(
                    'El descuento máximo es Bs %s (%d %% del subtotal).',
                    number_format($max, 2, ',', '.'),
                    $policy->maxPercent($this->user())
                ));
            }
        });
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
