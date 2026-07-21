<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$cat = App\Models\IngredientCategory::create(['name' => 'EnvasesTest']);
echo "Created: " . $cat->id . "\n";

// simulate controller destroy
$controller = app(\App\Http\Controllers\IngredientCategoryController::class);
$response = $controller->destroy($cat);
echo "Status: " . $response->getStatusCode() . "\n";

$catCheck = App\Models\IngredientCategory::withTrashed()->find($cat->id);
echo "Deleted at: " . $catCheck->deleted_at . "\n";
