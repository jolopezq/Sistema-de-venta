<?php

namespace App\Http\Controllers;

use App\Models\Option;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tarifario PedidosYa: guarda en lote los precios de plataforma de productos y opciones
 * (tamaños, toppings) y la disponibilidad de cada producto en PedidosYa.
 *
 * Solo toca `delivery_price` y `available_on_pedidosya`; el precio de mostrador nunca cambia.
 * Cada registro modificado queda en auditoría (trait Auditable de los modelos).
 */
class PedidosYaPricingController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'products'                          => ['nullable', 'array'],
            'products.*.id'                     => ['required', 'integer', 'exists:products,id'],
            'products.*.delivery_price'         => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'products.*.available_on_pedidosya' => ['sometimes', 'boolean'],
            'options'                           => ['nullable', 'array'],
            'options.*.id'                      => ['required', 'integer', 'exists:options,id'],
            'options.*.delivery_price'          => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ], [
            'products.*.delivery_price.min' => 'El precio PedidosYa no puede ser negativo.',
            'options.*.delivery_price.min'  => 'El precio PedidosYa no puede ser negativo.',
        ]);

        $updated = DB::transaction(function () use ($validated) {
            $products = 0;
            foreach ($validated['products'] ?? [] as $row) {
                $product = Product::findOrFail($row['id']);
                if (array_key_exists('delivery_price', $row)) {
                    $product->delivery_price = $row['delivery_price'];
                }
                if (array_key_exists('available_on_pedidosya', $row)) {
                    $product->available_on_pedidosya = $row['available_on_pedidosya'];
                }
                if ($product->isDirty()) {
                    $product->save();
                    $products++;
                }
            }

            $options = 0;
            foreach ($validated['options'] ?? [] as $row) {
                $option = Option::with('optionGroup')->findOrFail($row['id']);
                if (array_key_exists('delivery_price', $row) && $row['delivery_price'] !== null) {
                    if (!$option->optionGroup || !$option->optionGroup->pedidosya_pricing) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'options' => ['Este grupo cobra el mismo precio en PedidosYa.'],
                        ]);
                    }
                }
                if (array_key_exists('delivery_price', $row)) {
                    $option->delivery_price = $row['delivery_price'];
                }
                if ($option->isDirty()) {
                    $option->save();
                    $options++;
                }
            }

            return ['products' => $products, 'options' => $options];
        });

        return response()->json([
            'message'          => 'Tarifario PedidosYa actualizado.',
            'updated_products' => $updated['products'],
            'updated_options'  => $updated['options'],
        ]);
    }
}
