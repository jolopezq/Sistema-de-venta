<?php

namespace App\Services\PedidosYa;

use App\Models\Option;
use App\Models\PlatformCatalogMapping;
use App\Models\Product;
use App\Services\SalePricingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Traduce los artículos de PedidosYa a productos y opciones del POS (plan de Finanzas §6.4).
 *
 * Producto: equivalencia guardada (platform_catalog_mappings) → nombre idéntico (único) → sin resolver.
 * Opción:   nombre idéntico entre las opciones del producto → equivalencia guardada válida para el producto
 *           → sin resolver. Una equivalencia de opción con internal_id = 0 significa "sin equivalencia":
 *           el nombre se anota en la venta y no descuenta inventario.
 */
class CatalogResolver
{
    public const PLATFORM = 'pedidosya';
    public const IGNORE = 0;

    private Collection $products;
    /** @var array<string, int> */
    private array $productMap = [];
    /** @var array<string, int> */
    private array $optionMap = [];
    /** @var array<int, Collection> Opciones permitidas por producto (id → Option) */
    private array $optionsByProduct = [];

    public function __construct()
    {
        $this->products = Product::with(['optionGroups' => fn ($q) => $q->where('is_active', true), 'optionGroups.options' => fn ($q) => $q->where('is_active', true), 'excludedOptions:id'])
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        foreach (PlatformCatalogMapping::where('platform', self::PLATFORM)->get() as $m) {
            if ($m->entity_type === 'product') {
                $this->productMap[$m->external_id] = (int) $m->internal_id;
            } else {
                $this->optionMap[$m->external_id] = (int) $m->internal_id;
            }
        }
    }

    public static function normalize(string $name): string
    {
        return Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
    }

    public function product(int $id): ?Product
    {
        return $this->products->get($id);
    }

    /**
     * @return array{product: ?Product, via: ?string, suggestions: array}
     */
    public function resolveProduct(string $name): array
    {
        $key = self::normalize($name);

        if (isset($this->productMap[$key]) && $this->products->has($this->productMap[$key])) {
            return ['product' => $this->products->get($this->productMap[$key]), 'via' => 'mapping', 'suggestions' => []];
        }

        $exact = $this->products->filter(fn (Product $p) => self::normalize($p->name) === $key);
        if ($exact->count() > 1) {
            // Duplicados con el mismo nombre: se prefiere el que se vende en PedidosYa con precio propio
            $preferred = $exact->filter(fn (Product $p) => $p->available_on_pedidosya !== false && (float) $p->delivery_price > 0);
            $exact = $preferred->count() === 1 ? $preferred : $exact;
        }
        if ($exact->count() === 1) {
            return ['product' => $exact->first(), 'via' => 'name', 'suggestions' => []];
        }

        return ['product' => null, 'via' => null, 'suggestions' => $this->suggestProducts($key)];
    }

    /** Opciones activas que el producto admite (sin las excluidas para ese producto). */
    public function optionsFor(Product $product): Collection
    {
        if (!isset($this->optionsByProduct[$product->id])) {
            $excluded = $product->excludedOptions->pluck('id')->all();
            $this->optionsByProduct[$product->id] = $product->optionGroups
                ->flatMap(fn ($g) => $g->options->each(fn ($o) => $o->setRelation('optionGroup', $g)))
                ->reject(fn (Option $o) => in_array($o->id, $excluded, true))
                ->keyBy('id');
        }

        return $this->optionsByProduct[$product->id];
    }

    /**
     * @return array{option: ?Option, ignored: bool, via: ?string}
     */
    public function resolveOption(Product $product, string $name): array
    {
        $key = self::normalize($name);
        $options = $this->optionsFor($product);

        $exact = $options->filter(fn (Option $o) => self::normalize($o->name) === $key);
        if ($exact->isNotEmpty()) {
            return ['option' => $exact->first(), 'ignored' => false, 'via' => 'name'];
        }

        if (array_key_exists($key, $this->optionMap)) {
            $id = $this->optionMap[$key];
            if ($id === self::IGNORE) {
                return ['option' => null, 'ignored' => true, 'via' => 'mapping'];
            }
            if ($options->has($id)) {
                return ['option' => $options->get($id), 'ignored' => false, 'via' => 'mapping'];
            }
        }

        return ['option' => null, 'ignored' => false, 'via' => null];
    }

    /** Productos activos para elegir una equivalencia (los de PedidosYa primero). */
    public function productChoices(): array
    {
        return $this->products
            ->sortBy(fn (Product $p) => [$p->available_on_pedidosya === false ? 1 : 0, $p->name])
            ->map(fn (Product $p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'price' => SalePricingService::productPrice($p, 'pedidosya'),
            ])
            ->values()
            ->all();
    }

    /** Opciones posibles para un nombre, a partir de los productos donde apareció. */
    public function optionChoices(array $productIds): array
    {
        return collect($productIds)
            ->map(fn ($id) => $this->products->get($id))
            ->filter()
            ->flatMap(fn (Product $p) => $this->optionsFor($p)->values())
            ->unique('id')
            ->map(fn (Option $o) => ['id' => $o->id, 'name' => $o->name, 'group' => $o->optionGroup?->name])
            ->sortBy([['group', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    private function suggestProducts(string $key): array
    {
        $words = array_filter(explode(' ', $key), fn ($w) => strlen($w) > 2);

        return $this->products
            ->map(function (Product $p) use ($key, $words) {
                $name = self::normalize($p->name);
                similar_text($key, $name, $percent);
                $shared = count(array_intersect($words, explode(' ', $name)));
                // A igual parecido, primero el que tiene precio PedidosYa propio
                $pya = $p->available_on_pedidosya !== false && (float) $p->delivery_price > 0 ? 1 : 0;
                return ['id' => $p->id, 'name' => $p->name, 'score' => $percent + $shared * 25 + $pya, 'price' => SalePricingService::productPrice($p, 'pedidosya')];
            })
            ->filter(fn ($s) => $s['score'] >= 40)
            ->sortByDesc('score')
            ->take(3)
            ->map(fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'price' => $s['price']])
            ->values()
            ->all();
    }
}
