<?php

namespace App\Services;

use App\Models\CashRegisterSession;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Determina a qué sesión de caja pertenece una venta.
 *
 * Ventana de una sesión: desde opened_at hasta closed_at, y nunca más allá del fin del día en que se abrió.
 * Así una sesión olvidada abierta no absorbe ventas de días posteriores, y una caja de fecha pasada
 * (abierta desde Control diario) no se queda con las ventas de las semanas hasta el día en que se cerró:
 * su closed_at es el momento real del cierre, que puede ser días o semanas después.
 *
 * Prioridad:
 *  1. la sesión del propio cajero;
 *  2. si no tiene, la caja compartida de un admin/super_admin;
 *  3. si quien vende es admin/super_admin y no tiene caja propia, el turno que esté abierto en ese momento
 *     (el local tiene una sola gaveta: lo que cobra el dueño durante el turno de un cajero entra a ese turno).
 * Entre varias candidatas gana la abierta más reciente.
 */
class CashSessionResolver
{
    public function resolve(?int $cashierId, CarbonInterface $at): ?CashRegisterSession
    {
        $candidates = CashRegisterSession::with('cashier:id,role')
            ->where('opened_at', '<=', $at)
            ->where('opened_at', '>=', $at->copy()->startOfDay())
            ->where(function ($q) use ($at) {
                $q->whereNull('closed_at')->orWhere('closed_at', '>=', $at);
            })
            ->orderByDesc('opened_at')
            ->get();

        $own = $candidates->firstWhere('cashier_id', $cashierId);
        if ($own) {
            return $own;
        }

        $shared = $candidates->first(fn ($s) => in_array($s->cashier?->role, ['admin', 'super_admin'], true));
        if ($shared) {
            return $shared;
        }

        if ($candidates->isEmpty() || !$cashierId) {
            return null;
        }

        $sellerIsAdmin = in_array(User::whereKey($cashierId)->value('role'), ['admin', 'super_admin'], true);

        return $sellerIsAdmin ? $candidates->first() : null;
    }
}
