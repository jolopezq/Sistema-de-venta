<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/api/ingredient-categories/2', 'DELETE');
// We need to bypass auth for this test or login a user.
// Let's just create a user and act as them.
