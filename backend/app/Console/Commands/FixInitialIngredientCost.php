<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use Illuminate\Console\Command;

/**
 * Corrige el costo promedio (CPP) de los insumos cargados antes de que el alta lo fijara.
 *
 * Esos insumos tienen stock y costo, pero CPP en 0; el cálculo de costos ya usa el último costo como respaldo,
 * pero conviene dejar el CPP guardado. Cambia datos reales: sin `--confirm` solo muestra lo que cambiaría.
 */
class FixInitialIngredientCost extends Command
{
    protected $signature = 'inventory:fix-initial-cost {--confirm : Aplica el cambio (sin esta opción solo se muestra)}';

    protected $description = 'Fija el CPP inicial (= último costo cargado) de los insumos con stock y CPP en 0';

    public function handle(): int
    {
        $pending = Ingredient::where('tracks_stock', true)
            ->where('current_stock', '>', 0)
            ->where('unit_cost', '>', 0)
            ->where(fn ($q) => $q->whereNull('weighted_avg_cost')->orWhere('weighted_avg_cost', '<=', 0))
            ->orderBy('name')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No hay insumos por corregir.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Insumo', 'Stock', 'Costo cargado', 'CPP actual', 'CPP nuevo'],
            $pending->map(fn (Ingredient $i) => [
                $i->id,
                $i->name,
                (float) $i->current_stock . ' ' . $i->unit,
                (float) $i->unit_cost,
                (float) $i->weighted_avg_cost,
                (float) $i->unit_cost,
            ])->all()
        );

        if (!$this->option('confirm')) {
            $this->warn('Solo se mostró lo que cambiaría. Haz un respaldo de la base y ejecuta de nuevo con --confirm para aplicarlo.');

            return self::SUCCESS;
        }

        foreach ($pending as $ingredient) {
            $ingredient->update(['weighted_avg_cost' => $ingredient->unit_cost]);
        }
        $this->info("CPP corregido en {$pending->count()} insumo(s).");

        return self::SUCCESS;
    }
}
