<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        $suppliers = Supplier::withCount(['ingredients', 'expenses'])->orderBy('name')->get();

        return response()->json($suppliers);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated() + ['delivery_days' => 1]);

        return response()->json($supplier->loadCount(['ingredients', 'expenses']), 201);
    }

    public function update(SupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($request->validated());

        return response()->json($supplier->fresh()->loadCount(['ingredients', 'expenses']));
    }

    /** Con compras o insumos asociados no se elimina (se desactiva): el historial sigue mostrando su nombre. */
    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($supplier->ingredients()->exists() || $supplier->expenses()->exists()) {
            return response()->json([
                'message' => 'Tiene compras o insumos asociados. Desactívalo para que no aparezca al comprar.',
            ], 422);
        }
        $supplier->delete();

        return response()->json(['message' => 'Proveedor eliminado.']);
    }
}
