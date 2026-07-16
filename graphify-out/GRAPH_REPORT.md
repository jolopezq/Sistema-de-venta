# Graph Report - .  (2026-07-16)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 458 nodes · 731 edges · 50 communities (47 shown, 3 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 20 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `707c90f3`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Illuminate\Database\Eloquent\Factories\HasFactory
- Category
- Pos.vue
- composer.json
- Product
- Illuminate\Foundation\Http\FormRequest
- scripts
- package.json
- Illuminate\Database\Eloquent\Relations\HasMany
- devDependencies
- Customer
- SaleSyncTest.php
- UserFactory
- AppServiceProvider
- ExampleTest
- HelloWorld.vue

## God Nodes (most connected - your core abstractions)
1. `Product` - 20 edges
2. `Category` - 19 edges
3. `Ingredient` - 19 edges
4. `Sale` - 18 edges
5. `Customer` - 15 edges
6. `User` - 15 edges
7. `Recipe` - 13 edges
8. `Controller` - 11 edges
9. `CategoryResource` - 10 edges
10. `ProductResource` - 10 edges

## Surprising Connections (you probably didn't know these)
- `CustomerController` --inherits--> `Controller`  [EXTRACTED]
  backend/app/Http/Controllers/CustomerController.php → backend/app/Http/Controllers/Controller.php
- `IngredientController` --inherits--> `Controller`  [EXTRACTED]
  backend/app/Http/Controllers/IngredientController.php → backend/app/Http/Controllers/Controller.php
- `InventoryMovementController` --inherits--> `Controller`  [EXTRACTED]
  backend/app/Http/Controllers/InventoryMovementController.php → backend/app/Http/Controllers/Controller.php
- `ProductController` --inherits--> `Controller`  [EXTRACTED]
  backend/app/Http/Controllers/ProductController.php → backend/app/Http/Controllers/Controller.php
- `SaleController` --inherits--> `Controller`  [EXTRACTED]
  backend/app/Http/Controllers/SaleController.php → backend/app/Http/Controllers/Controller.php

## Import Cycles
- None detected.

## Communities (50 total, 3 thin omitted)

### Community 0 - "Illuminate\Database\Eloquent\Factories\HasFactory"
Cohesion: 0.07
Nodes (16): InventoryMovementController, CashRegisterSession, DeliveryOrder, Ingredient, InventoryMovement, Recipe, Sale, SaleItem (+8 more)

### Community 1 - "Category"
Cohesion: 0.08
Nodes (14): AuthController, CatalogController, CategoryController, Controller, LoyaltyConfigController, RecipeController, CategoryRequest, CategoryResource (+6 more)

### Community 2 - "Pos.vue"
Cohesion: 0.06
Nodes (25): network, network, db, app, router, routes, apiFetch(), syncPendingSales() (+17 more)

### Community 3 - "composer.json"
Cohesion: 0.05
Nodes (41): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+33 more)

### Community 4 - "Product"
Cohesion: 0.10
Nodes (9): IngredientController, ProductController, IngredientRequest, ProductRequest, IngredientResource, ProductResource, Product, Illuminate\Http\Resources\Json\AnonymousResourceCollection (+1 more)

### Community 5 - "Illuminate\Foundation\Http\FormRequest"
Cohesion: 0.10
Nodes (7): SaleController, InventoryMovementRequest, LoginRequest, RecipeRequest, SyncSalesRequest, SaleSyncService, Illuminate\Foundation\Http\FormRequest

### Community 6 - "scripts"
Cohesion: 0.08
Nodes (26): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+18 more)

### Community 7 - "package.json"
Cohesion: 0.09
Nodes (22): dexie, dependencies, dexie, pinia, vue, vue-router, devDependencies, vite (+14 more)

### Community 8 - "Illuminate\Database\Eloquent\Relations\HasMany"
Cohesion: 0.13
Nodes (7): User, DatabaseSeeder, Illuminate\Database\Eloquent\Relations\HasMany, Illuminate\Database\Seeder, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, Laravel\Sanctum\HasApiTokens

### Community 9 - "devDependencies"
Cohesion: 0.11
Nodes (17): devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, vite, private (+9 more)

### Community 10 - "Customer"
Cohesion: 0.24
Nodes (4): CustomerController, CustomerRequest, CustomerResource, Customer

### Community 11 - "SaleSyncTest.php"
Cohesion: 0.27
Nodes (5): ExampleTest, SaleSyncTest, TestCase, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase

### Community 12 - "UserFactory"
Cohesion: 0.32
Nodes (4): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, self, static

## Knowledge Gaps
- **87 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+82 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **3 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Ingredient` connect `Illuminate\Database\Eloquent\Factories\HasFactory` to `Illuminate\Database\Eloquent\Relations\HasMany`, `SaleSyncTest.php`, `Product`?**
  _High betweenness centrality (0.025) - this node is a cross-community bridge._
- **Why does `Product` connect `Product` to `Illuminate\Database\Eloquent\Factories\HasFactory`, `Category`, `Illuminate\Foundation\Http\FormRequest`, `Illuminate\Database\Eloquent\Relations\HasMany`, `SaleSyncTest.php`?**
  _High betweenness centrality (0.024) - this node is a cross-community bridge._
- **Why does `Category` connect `Category` to `Illuminate\Database\Eloquent\Factories\HasFactory`, `Illuminate\Database\Eloquent\Relations\HasMany`, `SaleSyncTest.php`?**
  _High betweenness centrality (0.024) - this node is a cross-community bridge._
- **Are the 4 inferred relationships involving `Product` (e.g. with `.index()` and `.processSale()`) actually correct?**
  _`Product` has 4 INFERRED edges - model-reasoned connections that need verification._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _87 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Illuminate\Database\Eloquent\Factories\HasFactory` be split into smaller, more focused modules?**
  _Cohesion score 0.06939890710382514 - nodes in this community are weakly interconnected._
- **Should `Category` be split into smaller, more focused modules?**
  _Cohesion score 0.0841813135985199 - nodes in this community are weakly interconnected._