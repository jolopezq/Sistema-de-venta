<?php

namespace App\Services;

use App\Models\Option;
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
     * Pagos de un pedido de plataforma: un único pago 'delivery_platform' por el total.
     * El dinero lo cobra la plataforma, así que nunca debe sumar como efectivo, QR o tarjeta.
     *
     * @return array{0: array, 1: string[]} Pagos normalizados y discrepancias.
     */
    public function normalizePlatformPayments(array $payments, float $total): array
    {
        $paid = round(array_sum(array_column($payments, 'amount')), 2);
        $methods = array_unique(array_column($payments, 'method'));

        if ($methods === ['delivery_platform'] && abs($paid - $total) <= self::TOLERANCE) {
            return [$payments, []];
        }

        $sent = implode(', ', array_map(fn ($p) => "{$p['method']} {$p['amount']}", $payments)) ?: 'sin pagos';

        return [
            [['method' => 'delivery_platform', 'amount' => $total]],
            ["Pedido PedidosYa con pagos ({$sent}); se registró un único pago de plataforma por {$total}"],
        ];
    }

    /**
     * Precio de un producto según el canal: PedidosYa usa delivery_price si está definido.
     */
    public static function productPrice(Product $product, string $source = 'pos'): float
    {
        if ($source === 'pedidosya' && (float) $product->delivery_price > 0) {
            return (float) $product->delivery_price;
        }

        return (float) $product->price;
    }

    /**
     * Precio adicional de una opción (tamaño, topping) según el canal.
     */
    public static function optionPrice(Option $option, string $source = 'pos'): float
    {
        if ($source === 'pedidosya' && (float) $option->delivery_price > 0) {
            return (float) $option->delivery_price;
        }

        return (float) $option->additional_price;
    }

    /**
     * Precio unitario que corresponde según el catálogo vigente y el canal de la venta
     * (producto + opciones elegidas). Null si no se puede calcular (venta por peso sin gramos).
     *
     * @param Collection $selectedOptions Opciones elegidas, indexadas por id.
     */
    public function expectedUnitPrice(Product $product, array $itemData, Collection $selectedOptions, string $source = 'pos'): ?float
    {
        $extras = 0.0;
        foreach ($itemData['modifiers'] ?? [] as $mod) {
            $option = $selectedOptions->get($mod['option_id']);
            if ($option) {
                $extras += self::optionPrice($option, $source) * (float) ($mod['quantity'] ?? 1);
            }
        }

        if ($product->is_weight_based) {
            if (empty($itemData['weight_grams'])) {
                return null; // Sin gramos no hay forma de verificar el precio por peso
            }
            $pricePerGram = (float) ($product->price_per_gram ?: self::DEFAULT_PRICE_PER_GRAM);

            return (float) round((float) $itemData['weight_grams'] * $pricePerGram + $extras);
        }

        return round(self::productPrice($product, $source) + $extras, 2);
    }

    /**
     * Indica si el precio cobrado difiere del esperado más allá del redondeo.
     */
    public function differsFromExpected(?float $expected, float $charged): bool
    {
        return $expected !== null && abs($expected - $charged) > self::TOLERANCE;
    }

    /**
     * Compara el precio unitario cobrado con el que resulta del catálogo vigente
     * para el canal de la venta.
     *
     * @param Collection $selectedOptions Opciones elegidas, indexadas por id.
     * @return string|null Descripción de la diferencia, o null si coincide.
     */
    public function catalogPriceMismatch(Product $product, array $itemData, Collection $selectedOptions, string $source = 'pos'): ?string
    {
        $expected = $this->expectedUnitPrice($product, $itemData, $selectedOptions, $source);
        $charged = (float) $itemData['unit_price'];

        if (!$this->differsFromExpected($expected, $charged)) {
            return null;
        }

        return "{$product->name}: cobrado {$charged}, catálogo {$expected}";
    }
}
