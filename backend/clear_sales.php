<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    Schema::disableForeignKeyConstraints();

    if (Schema::hasTable('sale_item_options')) {
        DB::table('sale_item_options')->truncate();
    }
    if (Schema::hasTable('sale_items')) {
        DB::table('sale_items')->truncate();
    }
    if (Schema::hasTable('sale_payments')) {
        DB::table('sale_payments')->truncate();
    }
    if (Schema::hasTable('delivery_orders')) {
        DB::table('delivery_orders')->truncate();
    }
    if (Schema::hasTable('sales')) {
        DB::table('sales')->truncate();
    }
    if (Schema::hasTable('cash_register_sessions')) {
        DB::table('cash_register_sessions')->truncate();
    }
    if (Schema::hasTable('inventory_movements')) {
        DB::table('inventory_movements')
            ->where('type', 'sale')
            ->orWhereIn('reference_type', ['sale_item', 'sale_item_option'])
            ->delete();
    }

    Schema::enableForeignKeyConstraints();

    echo "✓ ¡Tablas de órdenes, pedidos, pagos y sesiones de caja limpiadas con éxito!\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

