<?php

namespace App\Repositories;

use App\Models\Customer;

/**
 * Repositorio de Clientes.
 *
 * Centraliza las consultas CRM para desacoplar la lógica de puntos
 * de fidelización de los Servicios que la consumen.
 */
class CustomerRepository
{
    /**
     * Busca un cliente por su ID. Retorna null si no existe.
     */
    public function find(int $id): ?Customer
    {
        return Customer::find($id);
    }

    /**
     * Acumula puntos de fidelización de forma atómica.
     *
     * Usa `increment()` en lugar de leer-modificar-guardar para
     * evitar race conditions en entornos multi-cajero.
     *
     * IMPORTANTE: Llamar siempre dentro de DB::transaction().
     *
     * @param Customer $customer  El cliente a actualizar.
     * @param int      $points    Puntos a sumar (debe ser > 0).
     */
    public function incrementPoints(Customer $customer, int $points): void
    {
        if ($points <= 0) {
            return;
        }

        // Actualización atómica a nivel SQL — sin race condition
        $customer->increment('loyalty_points', $points);
    }
}
