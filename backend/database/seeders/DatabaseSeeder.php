<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Crear Cajero de prueba
        User::create([
            'name' => 'Admin Cajero',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'pin' => '1234',
        ]);

        // 2. Crear Insumos
        $acai = Ingredient::create(['name' => 'Pulpa de Açaí', 'unit' => 'kg', 'current_stock' => 50, 'unit_cost' => 12]);
        $granola = Ingredient::create(['name' => 'Granola', 'unit' => 'kg', 'current_stock' => 10, 'unit_cost' => 5]);
        $banana = Ingredient::create(['name' => 'Banana', 'unit' => 'unidades', 'current_stock' => 100, 'unit_cost' => 0.5]);
        
        // 3. Crear Categorías
        $catBowls = Category::create(['name' => 'Açaí Bowls', 'sort_order' => 1]);
        $catSmoothies = Category::create(['name' => 'Smoothies', 'sort_order' => 2]);

        // 4. Crear Productos y Recetas
        $bowlP = Product::create(['name' => 'Bowl Pequeño', 'price' => 25, 'category_id' => $catBowls->id, 'printer_target' => 'kitchen']);
        Recipe::create(['product_id' => $bowlP->id, 'ingredient_id' => $acai->id, 'quantity_required' => 0.150]);
        Recipe::create(['product_id' => $bowlP->id, 'ingredient_id' => $granola->id, 'quantity_required' => 0.050]);
        Recipe::create(['product_id' => $bowlP->id, 'ingredient_id' => $banana->id, 'quantity_required' => 0.5]);

        $bowlG = Product::create(['name' => 'Bowl Grande', 'price' => 45, 'category_id' => $catBowls->id, 'printer_target' => 'kitchen']);
        Recipe::create(['product_id' => $bowlG->id, 'ingredient_id' => $acai->id, 'quantity_required' => 0.300]);
        Recipe::create(['product_id' => $bowlG->id, 'ingredient_id' => $granola->id, 'quantity_required' => 0.100]);
        Recipe::create(['product_id' => $bowlG->id, 'ingredient_id' => $banana->id, 'quantity_required' => 1]);

        $smoothie = Product::create(['name' => 'Smoothie Tropical', 'price' => 20, 'category_id' => $catSmoothies->id, 'printer_target' => 'kitchen']);
        Recipe::create(['product_id' => $smoothie->id, 'ingredient_id' => $acai->id, 'quantity_required' => 0.100]);
    }
}
