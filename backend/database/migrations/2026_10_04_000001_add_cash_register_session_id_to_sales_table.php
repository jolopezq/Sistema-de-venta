<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vincula cada venta con su sesión de caja.
     *
     * El backfill replica la regla por rango de horas que usaba el arqueo hasta ahora,
     * para que los reportes de sesiones existentes no cambien: primero las sesiones de
     * cajeros (solo sus ventas), luego las de admin/super_admin (ventas de todos),
     * y dentro de cada grupo gana la sesión abierta más reciente.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_register_session_id')->nullable()->index()->after('cashier_id');
        });

        $sessions = DB::table('cash_register_sessions as c')
            ->join('users as u', 'u.id', '=', 'c.cashier_id')
            ->select('c.id', 'c.cashier_id', 'c.opened_at', 'c.closed_at', 'u.role')
            ->orderByDesc('c.opened_at')
            ->get();

        $isShared = fn ($s) => in_array($s->role, ['admin', 'super_admin'], true);

        foreach ([$sessions->reject($isShared), $sessions->filter($isShared)] as $group) {
            foreach ($group as $session) {
                $openedAt = Carbon::parse($session->opened_at);
                $end = $session->closed_at
                    ? Carbon::parse($session->closed_at)
                    : $openedAt->copy()->endOfDay();

                $query = DB::table('sales')
                    ->whereNull('cash_register_session_id')
                    ->whereBetween('created_at', [$openedAt->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);

                if (!$isShared($session)) {
                    $query->where('cashier_id', $session->cashier_id);
                }

                $query->update(['cash_register_session_id' => $session->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['cash_register_session_id']);
            $table->dropColumn('cash_register_session_id');
        });
    }
};
