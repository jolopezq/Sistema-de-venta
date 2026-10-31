<?php

namespace App\Services;

use App\Models\Sale;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fecha de inicio del sistema: las ventas anteriores se cargan como históricas (no descuentan stock ni suman puntos).
 * Por defecto es el día de la primera venta del POS; el Super Admin puede fijarla en Control diario.
 * Ver docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md §3.3.
 */
class SystemStartDate
{
    public const KEY = 'system_start_date';

    /** @return array{date: ?string, configured: bool} */
    public function info(): array
    {
        $configured = DB::table('app_settings')->where('key', self::KEY)->value('value');
        if ($configured) {
            return ['date' => $configured, 'configured' => true];
        }
        $first = Sale::where('source', 'pos')->min('created_at');

        return ['date' => $first ? Carbon::parse($first)->toDateString() : null, 'configured' => false];
    }

    public function date(): ?Carbon
    {
        $date = $this->info()['date'];

        return $date ? Carbon::parse($date, config('app.timezone'))->startOfDay() : null;
    }

    /** Una venta es histórica si su fecha es anterior al día de inicio del sistema. */
    public function isHistorical(CarbonInterface $at): bool
    {
        $start = $this->date();

        return $start !== null && $at->lt($start);
    }

    /** null = volver a la fecha de la primera venta del POS. */
    public function set(?string $date): void
    {
        if ($date === null) {
            DB::table('app_settings')->where('key', self::KEY)->delete();
            return;
        }
        DB::table('app_settings')->updateOrInsert(['key' => self::KEY], ['value' => $date, 'updated_at' => now(), 'created_at' => now()]);
    }
}
