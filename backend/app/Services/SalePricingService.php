<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Recalcula en el servidor los importes de una venta sincronizada desde el POS.
 *
 * El precio unitario cobrado se respeta (el cliente ya pagó y el catálogo pudo
 * cambiar mientras el POS estaba offline), pero los subtotales, el total y los
 * pagos se derivan aquí. Cualquier diferencia con lo que envió el POS o con el
 * catálogo vigente se devuelve como discrepancia para dejarla en auditoría.
 */
class SalePricingService
{
    private const TOLERANCE = 0.01;

    // Tarifa por gramo que usa el POS cuando el producto no tiene una definida
    private const DEFAULT_PRICE_PER_GRAM = 0.08;

    /**
     * Recalcula subtotales de ítems, subtotal, descuento y total de la venta.
     *
     * @return array{0: array, 1: string[]} Datos normalizados y discrepancias.
     */
    public function normalizeTotals(array $data): array
    {
        $issues = [];
        $subtotal = 0.0;

        foreach ($data['items'] as $i => $item) {
            $itemSubtotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            if (abs($itemSubtotal - (float) $item['subtotal']) > self::TOLERANCE) {
                $issues[] = "Ítem #" . ($i + 1) . ": subtotal enviado {$item['subtotal']}, recalculado {$itemSubtotal}";
            }
            $data['items'][$i]['subtotal'] = $itemSubtotal;
            $subtotal += $itemSubtotal;
        }

        $subtotal = round($subtotal, 2);
        $discount = round(min((float) ($data['discount_amount'] ?? 0), $subtotal), 2);
        $total = round($subtotal - $discount, 2);

        if (abs($subtotal - (float) $data['subtotal']) > self::TOLERANCE) {
            $issues[] = "Subtotal enviado {$data['subtotal']}, recalculado {$subtotal}";
        }
        if (abs($total - (float) $data['total_amount']) > self::TOLERANCE) {
            $issues[] = "Total enviado {$data['total_amount']}, recalculado {$total}";
        }

        $data['subtotal'] = $subtotal;
        $data['discount_amount'] = $discount;
        $data['total_amount'] = $total;

        return [$data, $issues];
    }

    /**
     * Ajusta los pagos para que sumen el total cobrado.
     *
     * Si se registró más de lo cobrado, el exceso es el cambio devuelto en efectivo
     * y se descuenta del pago en efectivo; así el arqueo no cuenta el cambio como ingreso.
     *
     * @return array{0: array, 1: string[]} Pagos normalizados y discrepancias.
     */
    public function normalizePayments(array $payments, float $total): array
    {
        $issues = [];
        $paid = round(array_sum(array_column($payments, 'amount')), 2);
        $excess = round($paid - $total, 2);

        if ($excess > self::TOLERANCE) {
            foreach ($payments as $i => $payment) {
                if ($payment['method'] !== 'cash' || $excess <= 0) {
                    continue;
                }
                $deduction = min($excess, (float) $payment['amount']);
                $payments[$i]['amount'] = round((float) $payment['amount'] - $deduction, 2);
                $excess = round($excess - $deduction, 2);
            }

            $payments = array_values(array_filter($payments, fn ($p) => (float) $p['amount'] > 0));
            $issues[] = "Pagos registrados por {$paid} sobre un total de {$total}; el cambio se descontó del efectivo";

            if ($excess > self::TOLERANCE) {
                $issues[] = "Quedan {$excess} pagados de más con métodos distintos a efectivo";
            }
        } elseif ($excess < -self::TOLERANCE) {
            $issues[] = "Pagos registrados por {$paid}, menores al total de {$total}";
        }

        return [$payments, $issues];
    }

    /**
     * Compara el precio unitario cobrado con el que resulta del catálogo vigente.
     *
     * @param Collection $selectedOptions Opciones elegidas, indexadas por id.
     * @return string|null Descripción de la diferencia, o null si coincide.
     */
    public function catalogPriceMismatch(Product $product, array $itemData, Collection $selectedOptions): ?string
    {
        $extras = 0.0;
        foreach ($itemData['modifiers'] ?? [] as $mod) {
            $option = $selectedOptions->get($mod['option_id']);
            if ($option) {
                $extras += (float) $option->additional_price * (float) ($mod['quantity'] ?? 1);
            }
        }

        if ($product->is_weight_based) {
            if (empty($itemData['weight_grams'])) {
                return null; // Sin gramos no hay forma de verificar el precio por peso
            }
            $pricePerGram = (float) ($product->price_per_gram ?: self::DEFAULT_PRICE_PER_GRAM);
            $expected = round((float) $itemData['weight_grams'] * $pricePerGram + $extras);
        } else {
            $expected = round((float) $product->price + $extras, 2);
        }

        $charged = (float) $itemData['unit_price'];
        if (abs($expected - $charged) <= self::TOLERANCE) {
            return null;
        }

        return "{$product->name}: cobrado {$charged}, catálogo {$expected}";
    }
}
