<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use App\Models\Supplier;
use App\Support\NameKey;
use Illuminate\Database\Seeder;

/**
 * Carga los parámetros de referencia de punto de reorden, vida útil, compras diarias
 * y proveedores clave para los insumos del sistema (Plan F4, §2.3 y §4).
 */
class ReorderDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Proveedores clave
        $supAcai = Supplier::firstOrCreate(
            ['name_key' => NameKey::make('Distribuidora Açaí')],
            [
                'name' => 'Distribuidora Açaí',
                'phone' => '70012345',
                'delivery_days' => 2,
                'order_days' => 'Lunes y jueves',
                'notes' => 'Proveedor de pulpa congelada de açaí',
                'is_active' => true,
            ]
        );

        $supGranola = Supplier::firstOrCreate(
            ['name_key' => NameKey::make('Granola Artesanal')],
            [
                'name' => 'Granola Artesanal',
                'phone' => '70023456',
                'delivery_days' => 3,
                'order_days' => 'Martes',
                'notes' => 'Proveedor de granola artesanal y secos',
                'is_active' => true,
            ]
        );

        $supMercado = Supplier::firstOrCreate(
            ['name_key' => NameKey::make('Mercado Central (Frutas)')],
            [
                'name' => 'Mercado Central (Frutas)',
                'phone' => '70034567',
                'delivery_days' => 1,
                'order_days' => 'Diario',
                'notes' => 'Compra de fruta fresca del día',
                'is_active' => true,
            ]
        );

        // 2. Parámetros para Pulpa de Açaí
        $pulpa = Ingredient::where('name', 'like', '%Pulpa%')->first();
        if ($pulpa) {
            $pulpa->update([
                'type' => 'perecedero',
                'is_key' => true,
                'supplier_id' => $supAcai->id,
                'purchase_unit' => 'bolsa',
                'purchase_factor' => 1.0000,
                'lot_size' => 1.0000,
                'reorder_policy' => 'reorder',
                'daily_estimate' => 2.9600,
                'target_days' => 7,
                'safety_days' => 2.00,
                'shelf_life_days' => 180,
                'minimum_stock' => 12.0000,
            ]);
        }

        // 3. Parámetros para Granola
        $granola = Ingredient::where('name', 'like', '%Granola%')->first();
        if ($granola) {
            $granola->update([
                'type' => 'no_perecedero',
                'is_key' => true,
                'supplier_id' => $supGranola->id,
                'purchase_unit' => 'bolsa',
                'purchase_factor' => 1.0000,
                'lot_size' => 1.0000,
                'reorder_policy' => 'reorder',
                'daily_estimate' => 0.3000,
                'target_days' => 14,
                'safety_days' => 2.00,
                'shelf_life_days' => 90,
                'minimum_stock' => 1.7000,
            ]);
        }

        // 4. Parámetros para Banana (Fruta fresca -> Compra diaria)
        $banana = Ingredient::where('name', 'like', '%Banana%')->first();
        if ($banana) {
            $banana->update([
                'type' => 'perecedero',
                'is_key' => true,
                'supplier_id' => $supMercado->id,
                'purchase_unit' => 'racimo',
                'purchase_factor' => 1.0000,
                'lot_size' => 1.0000,
                'reorder_policy' => 'daily',
                'daily_estimate' => 12.0000,
                'target_days' => 1,
                'safety_days' => 1.00,
                'shelf_life_days' => 3,
                'minimum_stock' => 0.0000,
            ]);
        }
    }
}
