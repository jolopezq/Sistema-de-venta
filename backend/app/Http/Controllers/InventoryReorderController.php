<?php

namespace App\Http\Controllers;

use App\Services\ReorderService;
use Illuminate\Http\JsonResponse;

/**
 * Punto de reorden y compras sugeridas (plan de inventario, F4). Solo lectura: las alertas se calculan al
 * consultar y no se guardan. Es de administrador porque muestra costos y proveedores.
 */
class InventoryReorderController extends Controller
{
    public function __construct(protected ReorderService $reorder) {}

    /** Estado de reposición de cada insumo con control de stock, con la cantidad sugerida a pedir. */
    public function index(): JsonResponse
    {
        return response()->json($this->reorder->report());
    }

    /** Conteos y urgentes: para la marca del menú, la tarjeta de Finanzas y el aviso al abrir caja. */
    public function summary(): JsonResponse
    {
        return response()->json($this->reorder->summary());
    }
}
