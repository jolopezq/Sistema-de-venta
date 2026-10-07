<?php

namespace App\Http\Controllers;

use App\Models\Option;
use App\Models\PlatformCatalogMapping;
use App\Models\Product;
use App\Services\PedidosYa\CatalogResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Equivalencias de nombres PedidosYa ↔ POS (plan de Finanzas §6.4), en `platform_catalog_mappings`.
 * `external_id` guarda el nombre normalizado; una opción con `internal_id` = 0 se anota sin descontar inventario.
 */
class PedidosYaMappingController extends Controller
{
    public function index(): JsonResponse
    {
        $mappings = PlatformCatalogMapping::where('platform', CatalogResolver::PLATFORM)->orderBy('entity_type')->orderBy('external_id')->get();
        $products = Product::whereIn('id', $mappings->where('entity_type', 'product')->pluck('internal_id'))->pluck('name', 'id');
        $options = Option::with('optionGroup:id,name')->whereIn('id', $mappings->where('entity_type', 'option')->pluck('internal_id'))->get()->keyBy('id');

        return response()->json($mappings->map(fn ($m) => [
            'id'          => $m->id,
            'entity_type' => $m->entity_type,
            'name'        => $m->external_id,
            'internal_id' => (int) $m->internal_id,
            'target'      => $m->entity_type === 'product'
                ? ($products[$m->internal_id] ?? 'Producto eliminado')
                : ((int) $m->internal_id === CatalogResolver::IGNORE
                    ? 'Sin equivalencia (solo nota)'
                    : (($options[$m->internal_id]?->name ?? 'Opción eliminada') . ' · ' . ($options[$m->internal_id]?->optionGroup?->name ?? ''))),
        ])->values());
    }

    /**
     * Guarda varias equivalencias a la vez: [{entity_type, name, internal_id}].
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mappings'               => ['required', 'array', 'min:1'],
            'mappings.*.entity_type' => ['required', 'in:product,option'],
            'mappings.*.name'        => ['required', 'string', 'max:200'],
            'mappings.*.internal_id' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($data['mappings'] as $i => $m) {
            $exists = $m['entity_type'] === 'product'
                ? Product::where('id', $m['internal_id'])->exists()
                : ((int) $m['internal_id'] === CatalogResolver::IGNORE || Option::where('id', $m['internal_id'])->exists());
            if (!$exists) {
                throw ValidationException::withMessages(["mappings.{$i}.internal_id" => "«{$m['name']}»: el destino elegido no existe."]);
            }
        }

        DB::transaction(function () use ($data) {
            foreach ($data['mappings'] as $m) {
                PlatformCatalogMapping::updateOrCreate(
                    [
                        'platform'    => CatalogResolver::PLATFORM,
                        'entity_type' => $m['entity_type'],
                        'external_id' => CatalogResolver::normalize($m['name']),
                    ],
                    ['internal_id' => (int) $m['internal_id']]
                );
            }
        });

        return response()->json(['message' => 'Equivalencias guardadas.'], 201);
    }

    public function destroy(PlatformCatalogMapping $mapping): JsonResponse
    {
        abort_unless($mapping->platform === CatalogResolver::PLATFORM, 404);
        $mapping->delete();

        return response()->json(['message' => 'Equivalencia eliminada.']);
    }
}
