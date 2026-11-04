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
 *
 * Si ninguna caja estaba abierta a esa hora pero ese mismo día se abrió una después, la venta va a esa caja
 * (docs/PLAN_CONCILIACION_CAJA.md, C): una venta cargada en Control diario toma la hora del reloj y podía quedar
 * antes de la apertura (la 171026-0005 del 17/10 a las 02:57), o una venta sin conexión anterior a la apertura;
 * sin caja no entra a ningún arqueo.
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

        $picked = $this->pick($candidates, $cashierId);
        if ($picked) {
            return $picked;
        }

        // La caja que se abrió más tarde ese mismo día (la primera)
        $later = CashRegisterSession::with('cashier:id,role')
            ->where('opened_at', '>', $at)
            ->where('opened_at', '<=', $at->copy()->endOfDay())
            ->orderBy('opened_at')
            ->get();

        return $this->pick($later, $cashierId);
    }

    /** Prioridad: la caja del propio vendedor, la compartida de un admin, o cualquiera si vende un admin. */
    private function pick($candidates, ?int $cashierId): ?CashRegisterSession
    {
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
