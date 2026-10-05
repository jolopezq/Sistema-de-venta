<?php

namespace App\Services;

use App\Models\CashRegisterSession;
use Carbon\CarbonInterface;

/**
 * Determina a qué sesión de caja pertenece una venta.
 *
 * Ventana de una sesión: desde opened_at hasta closed_at; si sigue abierta,
 * hasta el fin del día en que se abrió (las sesiones olvidadas abiertas no
 * absorben ventas de días posteriores).
 *
 * Prioridad: la sesión del propio cajero; si no tiene, la caja compartida de
 * un admin/super_admin. Entre varias candidatas gana la abierta más reciente.
 */
class CashSessionResolver
{
    public function resolve(?int $cashierId, CarbonInterface $at): ?CashRegisterSession
    {
        $candidates = CashRegisterSession::with('cashier:id,role')
            ->where('opened_at', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->where('closed_at', '>=', $at)
                  ->orWhere(function ($q) use ($at) {
                      $q->whereNull('closed_at')
                        ->where('opened_at', '>=', $at->copy()->startOfDay());
                  });
            })
            ->orderByDesc('opened_at')
            ->get();

        return $candidates->firstWhere('cashier_id', $cashierId)
            ?? $candidates->first(fn ($s) => in_array($s->cashier?->role, ['admin', 'super_admin'], true));
    }
}
