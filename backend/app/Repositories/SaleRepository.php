<?php

namespace App\Repositories;

use App\Models\Sale;

/**
 * Repositorio de Ventas.
 *
 * Centraliza las consultas críticas del flujo de sincronización Offline-First.
 * La idempotencia (evitar duplicados por UUID) vive aquí, no en el Servicio.
 */
class SaleRepository
{
    /**
     * Verifica si una venta con ese UUID ya fue procesada.
     * Fundamento de la idempotencia de red (buenas-practicas.md §4).
     *
     * @param string $uuid UUID v4 generado en el frontend.
     */
    public function existsByUuid(string $uuid): bool
    {
        return Sale::where('id', $uuid)->exists();
    }

    /**
     * Crea una venta principal dentro de una transacción activa.
     * IMPORTANTE: Este método debe llamarse siempre dentro de DB::transaction().
     */
    public function create(array $data): Sale
    {
        return Sale::create($data);
    }
}
