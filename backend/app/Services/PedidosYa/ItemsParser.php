<?php

namespace App\Services\PedidosYa;

/**
 * Lee la columna "Artículos del pedido" del CSV de PedidosYa.
 *
 *   "4 Arma Tu Ohana Acai [1 Durazno, 1 Frutilla], 1 Classic Bowl"
 *   → [['quantity' => 4, 'name' => 'Arma Tu Ohana Acai', 'options' => [['quantity' => 1, 'name' => 'Durazno'], …]],
 *      ['quantity' => 1, 'name' => 'Classic Bowl', 'options' => []]]
 *
 * Las comas dentro de [...] separan opciones; las de afuera, artículos. Las opciones valen por unidad.
 */
class ItemsParser
{
    /**
     * @return array<int, array{quantity: int, name: string, options: array<int, array{quantity: int, name: string}>}>
     */
    public function parse(?string $text): array
    {
        $items = [];
        foreach ($this->splitTopLevel((string) $text) as $part) {
            if (!preg_match('/^(?:(\d+)\s*x?\s+)?([^\[]+?)\s*(?:\[(.*)\])?\s*$/u', $part, $m)) {
                continue;
            }

            $options = [];
            foreach (array_filter(array_map('trim', explode(',', $m[3] ?? ''))) as $opt) {
                if (preg_match('/^(?:(\d+)\s*x?\s+)?(.+)$/u', $opt, $o)) {
                    $options[] = ['quantity' => max(1, (int) ($o[1] ?: 1)), 'name' => trim($o[2])];
                }
            }

            $items[] = [
                'quantity' => max(1, (int) ($m[1] ?: 1)),
                'name'     => trim($m[2]),
                'options'  => $options,
            ];
        }

        return $items;
    }

    /** Separa por comas que no están dentro de corchetes. */
    private function splitTopLevel(string $text): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (mb_str_split($text) as $char) {
            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth = max(0, $depth - 1);
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }
}
