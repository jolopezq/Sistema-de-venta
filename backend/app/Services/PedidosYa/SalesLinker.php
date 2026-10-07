<?php

namespace App\Services\PedidosYa;

use App\Models\PedidosYaOrder;
use App\Models\PedidosYaSettlement;
use App\Models\Product;
use App\Models\Sale;
use App\Services\PedidosYaImportService;
use App\Services\SalePricingService;
use App\Services\SaleSyncService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Crea o vincula las ventas de los pedidos de una semana PedidosYa (plan de Finanzas §6.2–6.4).
 *
 * Por pedido entregado:
 *  - linked:    ya tiene venta (reimportación): no se toca
 *  - link:      coincide con una venta PedidosYa ingresada a mano (Nº de pedido en la nota, o misma hora ±30 min
 *               y mismo monto): se vincula, no se duplica
 *  - create:    se crea la venta con la fecha y hora del pedido, sus artículos y el monto de PedidosYa
 *  - error:     no se puede crear (p. ej. más opciones de las que admite el producto); se informa
 * Los cancelados no crean venta. La confirmación se bloquea mientras haya nombres sin equivalencia.
 */
class SalesLinker
{
    public const MATCH_MINUTES = 30;
    public const OVERRIDE_REASON = 'Precio PedidosYa (importado)';

    public function __construct(
        private ItemsParser $parser,
        private SaleSyncService $sales,
        private SalePricingService $pricing,
    ) {}

    /**
     * @param array $orders  Pedidos (order_number, status, order_date 'Y-m-d H:i[:s]', subtotal, items_text)
     * @param array $links   Elección del dueño: [order_number => sale_id | 'new']
     */
    public function analyze(array $orders, string $startDate, string $endDate, array $links = []): array
    {
        $resolver = new CatalogResolver();
        usort($orders, fn ($a, $b) => strcmp((string) $a['order_date'], (string) $b['order_date']));
        $numbers = array_column($orders, 'order_number');

        // Ventas ya vinculadas a estos pedidos (reimportación)
        $existingLinks = PedidosYaOrder::whereIn('order_number', $numbers)->whereNotNull('sale_id')->pluck('sale_id', 'order_number');
        $linkedSales = Sale::whereIn('id', $existingLinks->values())->get()->keyBy('id');

        // Ventas PedidosYa del POS de la semana que todavía no están vinculadas a ningún pedido
        $pool = Sale::where('source', 'pedidosya')
            ->where('status', 'completed')
            ->whereBetween('created_at', [
                Carbon::parse($startDate)->subDay()->toDateString() . ' 00:00:00',
                Carbon::parse($endDate)->addDay()->toDateString() . ' 23:59:59',
            ])
            ->whereNotIn('id', PedidosYaOrder::select('sale_id')->whereNotNull('sale_id'))
            ->orderBy('created_at')
            ->get(['id', 'order_number', 'created_at', 'total_amount', 'notes'])
            ->keyBy('id');
        $claimed = [];

        $rows = [];
        $unresolvedProducts = [];
        $unresolvedOptions = [];

        foreach ($orders as $order) {
            $number = (string) $order['order_number'];
            $total = round((float) $order['subtotal'], 2);
            $row = [
                'order_number' => $number,
                'status'       => $order['status'],
                'order_date'   => substr((string) $order['order_date'], 0, 16),
                'total'        => $total,
                'items_text'   => $order['items_text'] ?? '',
                'action'       => null,
                'sale'         => null,
                'candidates'   => [],
                'lines'        => [],
                'catalog_total'=> null,
                'notes'        => [],
                'errors'       => [],
                'unresolved'   => false,
            ];

            $existingId = $existingLinks->get($number);
            $existingSale = $existingId ? $linkedSales->get($existingId) : null;

            if (!PedidosYaImportService::isDelivered($order['status'])) {
                $row['action'] = 'cancelled';
                if ($existingSale && $existingSale->status === 'completed') {
                    $row['errors'][] = "El pedido figura como «{$order['status']}» pero tiene la venta #{$existingSale->order_number}: revísala en Control Diario.";
                    $row['sale'] = $this->saleSummary($existingSale);
                }
                $rows[] = $row;
                continue;
            }

            if ($existingSale) {
                $row['action'] = 'linked';
                $row['sale'] = $this->saleSummary($existingSale);
                $rows[] = $row;
                continue;
            }

            // Ventas del POS que pueden ser este pedido
            $candidates = $this->candidates($pool, $claimed, $number, (string) $order['order_date'], $total);
            $row['candidates'] = $candidates->map(fn ($s) => $this->saleSummary($s))->values()->all();

            $choice = $links[$number] ?? null;
            $linkTo = null;
            if ($choice === 'new') {
                $linkTo = null;
            } elseif ($choice && $pool->has($choice) && !isset($claimed[$choice])) {
                $linkTo = $pool->get($choice);
            } elseif ($candidates->isNotEmpty()) {
                $linkTo = $candidates->first();
                if ($candidates->count() > 1) {
                    $row['notes'][] = "{$candidates->count()} ventas del POS coinciden en hora y monto: elige cuál es.";
                }
            }

            if ($linkTo) {
                $claimed[$linkTo->id] = true;
                $row['action'] = 'link';
                $row['sale'] = $this->saleSummary($linkTo);
            } else {
                $row['action'] = 'create';
            }

            // Artículos (también para los vinculados, para comparar con el Tarifario)
            $parsed = $this->parser->parseDetailed($row['items_text']);
            $built = $this->buildLines($resolver, $parsed['items'], $total);
            if (empty($parsed['items'])) {
                $built['errors'][] = 'No se pudieron leer los artículos del pedido: regístralo a mano en Control Diario.';
            } elseif (!empty($parsed['unparsed'])) {
                $built['errors'][] = 'No se pudo leer «' . implode('», «', $parsed['unparsed']) . '»: regístralo a mano en Control Diario.';
            }
            $row['lines'] = $built['lines'];
            $row['catalog_total'] = $built['catalog_total'];
            $row['ignored'] = $built['ignored'];

            if ($row['action'] === 'create') {
                foreach ($built['unresolved_products'] as $name) {
                    $unresolvedProducts[$name][] = $number;
                }
                foreach ($built['unresolved_options'] as [$name, $productId]) {
                    $unresolvedOptions[$name]['orders'][] = $number;
                    $unresolvedOptions[$name]['products'][$productId] = true;
                }
                $row['unresolved'] = !empty($built['unresolved_products']) || !empty($built['unresolved_options']);
                $row['errors'] = array_merge($row['errors'], $built['errors']);
                if (!$row['unresolved'] && !empty($built['errors'])) {
                    $row['action'] = 'error';
                }
                $row['items_payload'] = $built['payload'];
            }

            $rows[] = $row;
        }

        $unresolved = [
            'products' => collect($unresolvedProducts)->map(fn ($orders, $name) => [
                'name'        => $name,
                'orders'      => count(array_unique($orders)),
                'suggestions' => $resolver->resolveProduct($name)['suggestions'],
            ])->values()->all(),
            'options' => collect($unresolvedOptions)->map(fn ($data, $name) => [
                'name'    => $name,
                'orders'  => count(array_unique($data['orders'])),
                'choices' => $resolver->optionChoices(array_keys($data['products'])),
            ])->values()->all(),
        ];

        $count = fn ($action) => count(array_filter($rows, fn ($r) => $r['action'] === $action));

        return [
            'orders'      => array_map(fn ($r) => array_diff_key($r, ['items_payload' => true]), $rows),
            'payloads'    => collect($rows)->filter(fn ($r) => isset($r['items_payload']))->mapWithKeys(fn ($r) => [$r['order_number'] => $r['items_payload']])->all(),
            'unresolved'  => $unresolved,
            'products'    => $resolver->productChoices(),
            'pos_sales'   => $pool->map(fn ($s) => $this->saleSummary($s))->values()->all(),
            'summary'     => [
                'create'    => $count('create'),
                'link'      => $count('link'),
                'linked'    => $count('linked'),
                'cancelled' => $count('cancelled'),
                'error'     => $count('error'),
                'unresolved_names' => count($unresolved['products']) + count($unresolved['options']),
                'price_differences' => count(array_filter($rows, fn ($r) => $r['catalog_total'] !== null && abs($r['catalog_total'] - $r['total']) > 0.01)),
            ],
        ];
    }

    /**
     * Crea o vincula las ventas de la semana. Lanza ValidationException si quedan nombres sin equivalencia.
     *
     * @return array{created: int, linked: int, already: int, cancelled: int, errors: array<int, string>}
     */
    public function sync(PedidosYaSettlement $settlement, array $links, int $userId): array
    {
        $orders = $settlement->orders()->get();
        $analysis = $this->analyze(
            $orders->map(fn (PedidosYaOrder $o) => [
                'order_number' => $o->order_number,
                'status'       => $o->status,
                'order_date'   => $o->order_date->format('Y-m-d H:i:s'),
                'subtotal'     => (float) $o->subtotal,
                'items_text'   => $o->items_text,
            ])->all(),
            $settlement->start_date->toDateString(),
            $settlement->end_date->toDateString(),
            $links
        );

        if ($analysis['summary']['unresolved_names'] > 0) {
            $names = collect($analysis['unresolved']['products'])->pluck('name')
                ->merge(collect($analysis['unresolved']['options'])->pluck('name'))
                ->implode(', ');
            throw ValidationException::withMessages([
                'mappings' => "Hay artículos de PedidosYa sin equivalencia en el POS: {$names}. Asígnalos antes de crear las ventas.",
            ]);
        }

        $byNumber = $orders->keyBy('order_number');
        $week = $settlement->start_date->format('d/m') . '–' . $settlement->end_date->format('d/m/Y');
        $result = ['created' => 0, 'linked' => 0, 'already' => 0, 'cancelled' => 0, 'errors' => []];

        foreach ($analysis['orders'] as $row) {
            $order = $byNumber->get($row['order_number']);

            switch ($row['action']) {
                case 'cancelled':
                    $result['cancelled']++;
                    foreach ($row['errors'] as $error) {
                        $result['errors'][] = "Pedido {$row['order_number']}: {$error}";
                    }
                    break;

                case 'linked':
                    $result['already']++;
                    break;

                case 'link':
                    $order->update(['sale_id' => $row['sale']['id'], 'sale_link' => 'linked', 'catalog_total' => $row['catalog_total']]);
                    $result['linked']++;
                    break;

                case 'create':
                    try {
                        $sale = $this->sales->adminCreateSale($this->saleData($row, $analysis['payloads'][$row['order_number']], $week, $userId), $userId);
                        $order->update(['sale_id' => $sale->id, 'sale_link' => 'created', 'catalog_total' => $row['catalog_total']]);
                        $result['created']++;
                    } catch (Throwable $e) {
                        $result['errors'][] = "Pedido {$row['order_number']}: {$e->getMessage()}";
                    }
                    break;

                case 'error':
                    $result['errors'][] = "Pedido {$row['order_number']}: " . implode(' ', $row['errors']);
                    break;
            }
        }

        return $result;
    }

    private function saleData(array $row, array $items, string $week, int $userId): array
    {
        $notes = ["PedidosYa #{$row['order_number']} · importado"];
        if (!empty($row['ignored'])) {
            $notes[] = 'Sin equivalencia: ' . implode(', ', array_unique($row['ignored']));
        }

        return [
            'created_at'      => $row['order_date'] . ':00',
            'source'          => 'pedidosya',
            'subtotal'        => $row['total'],
            'discount_amount' => 0,
            'total_amount'    => $row['total'],
            'is_takeaway'     => true,
            'notes'           => implode(' · ', $notes),
            'edit_reason'     => "Importación semanal PedidosYa {$week}",
            'cashier_id'      => $userId,
            'items'           => $items,
            'payments'        => [['method' => 'delivery_platform', 'amount' => $row['total']]],
        ];
    }

    /**
     * Arma las líneas de la venta: producto, opciones, precio del Tarifario y precio cobrado.
     * Si el total de PedidosYa difiere del Tarifario, la diferencia se reparte en proporción.
     */
    private function buildLines(CatalogResolver $resolver, array $parsed, float $total): array
    {
        $lines = [];
        $payload = [];
        $ignored = [];
        $errors = [];
        $unresolvedProducts = [];
        $unresolvedOptions = [];
        $catalogTotal = 0.0;

        foreach ($parsed as $item) {
            $resolved = $resolver->resolveProduct($item['name']);
            /** @var Product|null $product */
            $product = $resolved['product'];

            $line = [
                'quantity'     => $item['quantity'],
                'name'         => $item['name'],
                'product'      => $product ? ['id' => $product->id, 'name' => $product->name] : null,
                'options'      => [],
                'catalog_unit' => null,
                'unit_price'   => null,
            ];

            if (!$product) {
                $unresolvedProducts[] = $item['name'];
                $lines[] = $line;
                continue;
            }

            if ($product->is_weight_based) {
                $errors[] = "{$product->name} se vende por peso: regístralo a mano en Control Diario.";
            }

            $modifiers = [];
            $selected = collect();
            foreach ($item['options'] as $opt) {
                $r = $resolver->resolveOption($product, $opt['name']);
                if ($r['option']) {
                    $modifiers[] = ['option_id' => $r['option']->id, 'quantity' => $opt['quantity']];
                    $selected->put($r['option']->id, $r['option']);
                    $line['options'][] = ['name' => $opt['name'], 'option' => $r['option']->name, 'quantity' => $opt['quantity']];
                } elseif ($r['ignored']) {
                    $ignored[] = $opt['name'];
                    $line['options'][] = ['name' => $opt['name'], 'option' => null, 'ignored' => true, 'quantity' => $opt['quantity']];
                } else {
                    $unresolvedOptions[] = [$opt['name'], $product->id];
                    $line['options'][] = ['name' => $opt['name'], 'option' => null, 'quantity' => $opt['quantity']];
                }
            }

            $errors = array_merge($errors, $this->selectionErrors($product, $selected, $modifiers));

            $unit = (float) $this->pricing->expectedUnitPrice($product, ['modifiers' => $modifiers], $selected, 'pedidosya');
            $line['catalog_unit'] = $unit;
            $catalogTotal += $unit * $item['quantity'];
            $lines[] = $line;
            $payload[] = [
                'product_id'            => $product->id,
                'quantity'              => $item['quantity'],
                'catalog_unit'          => $unit,
                'modifiers'             => $modifiers,
                'is_takeaway'           => true,
                'price_override_reason' => self::OVERRIDE_REASON,
            ];
        }

        $catalogTotal = round($catalogTotal, 2);
        $payload = $this->distribute($payload, $total, $catalogTotal);
        foreach ($payload as $i => $p) {
            // Las líneas con producto resuelto están en el mismo orden en $lines (saltando las no resueltas)
            $lineIndex = $this->nthResolved($lines, $i);
            if ($lineIndex !== null) {
                $lines[$lineIndex]['unit_price'] = $p['unit_price'];
            }
        }

        return [
            'lines'               => $lines,
            'payload'             => $payload,
            'catalog_total'       => empty($unresolvedProducts) ? $catalogTotal : null,
            'ignored'             => $ignored,
            'errors'              => $errors,
            'unresolved_products' => array_values(array_unique($unresolvedProducts)),
            'unresolved_options'  => $unresolvedOptions,
        ];
    }

    /** Reparte el total de PedidosYa entre las líneas en proporción a su precio del Tarifario. */
    private function distribute(array $payload, float $total, float $catalogTotal): array
    {
        if (empty($payload)) {
            return [];
        }

        $units = array_sum(array_column($payload, 'quantity'));
        $assigned = 0.0;
        $last = count($payload) - 1;

        foreach ($payload as $i => $p) {
            $weight = $catalogTotal > 0 ? ($p['catalog_unit'] * $p['quantity']) / $catalogTotal : $p['quantity'] / max(1, $units);
            $unit = round(($total * $weight) / $p['quantity'], 2);
            $subtotal = round($unit * $p['quantity'], 2);
            if ($i === $last) {
                // El redondeo se ajusta en la última línea para que la venta sume exactamente el total de PedidosYa
                $subtotal = round($total - $assigned, 2);
            }
            $assigned += $subtotal;

            $payload[$i]['unit_price'] = $unit;
            $payload[$i]['subtotal'] = $subtotal;
            unset($payload[$i]['catalog_unit']);
        }

        return $payload;
    }

    private function nthResolved(array $lines, int $n): ?int
    {
        $seen = -1;
        foreach ($lines as $index => $line) {
            if ($line['product'] !== null && ++$seen === $n) {
                return $index;
            }
        }

        return null;
    }

    /** Mismas reglas de mínimo y máximo por grupo que aplica el registro de ventas. */
    private function selectionErrors(Product $product, Collection $selected, array $modifiers): array
    {
        $byGroup = [];
        foreach ($modifiers as $mod) {
            $option = $selected->get($mod['option_id']);
            if ($option) {
                $byGroup[$option->option_group_id] = ($byGroup[$option->option_group_id] ?? 0) + 1;
            }
        }

        $errors = [];
        foreach ($product->optionGroups as $group) {
            $count = $byGroup[$group->id] ?? 0;
            if ($count < $group->min_selections) {
                $errors[] = "{$product->name} requiere al menos {$group->min_selections} en «{$group->name}».";
            }
            if ($group->max_selections !== null && $count > $group->max_selections) {
                $errors[] = "{$product->name} admite como máximo {$group->max_selections} en «{$group->name}» ({$count} en el pedido).";
            }
        }

        return $errors;
    }

    /** Ventas del POS que pueden ser este pedido: Nº en la nota, o misma hora (±30 min) y mismo monto. */
    private function candidates(Collection $pool, array $claimed, string $number, string $orderDate, float $total): Collection
    {
        $available = $pool->reject(fn ($s) => isset($claimed[$s->id]));

        $byNote = $available->filter(fn ($s) => $s->notes && str_contains(preg_replace('/\s+/', '', $s->notes), '#' . $number));
        if ($byNote->isNotEmpty()) {
            return $byNote->values();
        }

        $at = Carbon::parse($orderDate);

        return $available
            ->filter(fn ($s) => abs($s->created_at->diffInMinutes($at, false)) <= self::MATCH_MINUTES
                && abs((float) $s->total_amount - $total) <= 0.01)
            ->sortBy(fn ($s) => abs($s->created_at->diffInMinutes($at, false)))
            ->values();
    }

    private function saleSummary(Sale $sale): array
    {
        return [
            'id'           => $sale->id,
            'order_number' => $sale->order_number,
            'created_at'   => $sale->created_at->format('Y-m-d H:i'),
            'total'        => (float) $sale->total_amount,
            'notes'        => $sale->notes,
            'status'       => $sale->status,
        ];
    }
}
