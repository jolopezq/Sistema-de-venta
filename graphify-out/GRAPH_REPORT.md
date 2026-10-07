# Graph Report - Sistema de Ventas  (2026-10-04)

## Corpus Check
- 255 files · ~633,155 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1815 nodes · 3023 edges · 173 communities (154 shown, 19 thin omitted)
- Extraction: 95% EXTRACTED · 5% INFERRED · 0% AMBIGUOUS · INFERRED: 142 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `b6f0874a`
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
- 0001_01_01_000000_create_users_table.php
- 0001_01_01_000001_create_cache_table.php
- 0001_01_01_000002_create_jobs_table.php
- 2026_07_16_000001_create_customers_table.php
- 2026_07_16_000002_create_categories_table.php
- 2026_07_16_000003_create_products_table.php
- 2026_07_16_000004_create_ingredients_table.php
- 2026_07_16_000005_create_recipes_table.php
- 2026_07_16_000006_create_sales_table.php
- 2026_07_16_000007_create_sale_payments_table.php
- 2026_07_16_000008_create_sale_items_table.php
- 2026_07_16_000009_create_inventory_movements_table.php
- 2026_07_16_000010_create_loyalty_config_table.php
- 2026_07_16_000011_create_cash_register_sessions_table.php
- 2026_07_16_000012_create_delivery_orders_table.php
- 2026_07_16_100837_create_personal_access_tokens_table.php
- artisan
- HelloWorld.vue
- app.php
- app.php
- cache.php
- database.php
- filesystems.php
- logging.php
- mail.php
- queue.php
- sanctum.php
- services.php
- session.php
- index.php
- app.js
- welcome.blade.php
- web.php
- vite.config.js
- vite.config.js
- Plan: canal PedidosYa — arqueo separado, precios por canal y registro desde el POS
- IngredientRepository
- ProductPauseModal.vue
- BackupService
- static
- Análisis del Funcionamiento del Sistema de Pedidos y Comandas (Ohana Acai V3)
- 3. ⚠️ Reglas Obligatorias de Compatibilidad (Desarrollo desde Mac)
- App.vue
- Guía para agentes — Ohana POS
- 🛣️ Ruta de Desarrollo Sugerida (Por Dependencias)
- AdminProductCard.vue
- AdminSaleManagementTest
- Diseño de Fase 1: Cimientos y Seguridad
- ProductOptionsModal.vue
- CashSessionLinkTest
- ProcessOfflineSaleJob.php
- require-dev
- setup
- README.md
- Closure
- KdsController
- SaleRepository
- SalePricingTest
- ProductRepository
- config
- ProductCard.vue
- ReceiptModal.vue
- CashSessionReport.vue
- PinModal.vue
- 🍏 Guía de Conexión desde el Mac al Servidor Windows (Ohana POS)
- SalePricingService
- psr-4
- require
- AssignOptionGroupsModal.vue
- InventoryMovementRequest
- index.js
- post-create-project-cmd
- ExampleTest
- capture_dark_mode.cjs
- capture_dark_pos.cjs
- extra
- test_api.sh
- task.md
- README.md
- HelloWorld.vue
- SaleSyncTest
- iniciar-pos.sh
- cart.js
- theme.js
- SalePayment
- CashSessionResolver.php
- audit-contrast.cjs
- CartItem.vue

## God Nodes (most connected - your core abstractions)
1. `User` - 64 edges
2. `Sale` - 62 edges
3. `Product` - 55 edges
4. `apiFetch()` - 47 edges
5. `Category` - 38 edges
6. `Ingredient` - 32 edges
7. `TestCase` - 32 edges
8. `CashRegisterSession` - 31 edges
9. `OptionGroup` - 23 edges
10. `Controller` - 22 edges

## Surprising Connections (you probably didn't know these)
- `logAction()` --calls--> `AuditLog`  [INFERRED]
  backend/app/Traits/Auditable.php → backend/app/Models/AuditLog.php
- `loadUsers()` --calls--> `apiFetch()`  [EXTRACTED]
  frontend/src/components/AdminSaleEditor.vue → frontend/src/services/api.js
- `fetchPermissions()` --calls--> `apiFetch()`  [EXTRACTED]
  frontend/src/views/AdminUsers.vue → frontend/src/services/api.js
- `fetchUsers()` --calls--> `apiFetch()`  [EXTRACTED]
  frontend/src/views/AuditLogs.vue → frontend/src/services/api.js
- `AuditLogController` --inherits--> `Controller`  [EXTRACTED]
  backend/app/Http/Controllers/AuditLogController.php → backend/app/Http/Controllers/Controller.php

## Import Cycles
- None detected.

## Communities (173 total, 19 thin omitted)

### Community 0 - "Illuminate\Database\Eloquent\Factories\HasFactory"
Cohesion: 0.06
Nodes (42): addProductToSale(), autoSyncPayments(), calculatedSubtotal, calculatedTotal, catalogStore, emit, errorMessage, filteredAvailableProducts (+34 more)

### Community 1 - "Category"
Cohesion: 0.07
Nodes (14): User, CategoryTest, ExampleTest, OrderQueueTest, RoleAuthorizationTest, SystemBackupTest, UserManagementTest, TestCase (+6 more)

### Community 2 - "Pos.vue"
Cohesion: 0.05
Nodes (34): emit, parsedChanges, props, emit, errorMessage, handleDownload(), isDownloading, onClose() (+26 more)

### Community 3 - "composer.json"
Cohesion: 0.08
Nodes (11): App\Traits\Auditable, DeliveryOrder, Ingredient, OptionRecipe, PlatformCatalogMapping, ProductVariant, Recipe, RolePermission (+3 more)

### Community 4 - "Product"
Cohesion: 0.06
Nodes (36): activeTab, alertAction(), alertMessage, authStore, confirmAction(), confirmCallback, confirmMessage, decidedCount (+28 more)

### Community 5 - "Illuminate\Foundation\Http\FormRequest"
Cohesion: 0.05
Nodes (36): activeCategory, activeCategoryProducts, activeOptionGroup, activeProductForModal, activeProductForPause, activeTab, alertMessage, categories (+28 more)

### Community 6 - "scripts"
Cohesion: 0.04
Nodes (47): activeTab, authStore, cardSales, cashExpenses, cashSales, closedReport, currentSession, currentStats (+39 more)

### Community 7 - "package.json"
Cohesion: 0.07
Nodes (31): alertAction(), alertMessage, categories, categoryForm, confirmAction(), confirmCallback, confirmMessage, deleteCategory() (+23 more)

### Community 8 - "Illuminate\Database\Eloquent\Relations\HasMany"
Cohesion: 0.07
Nodes (29): authStore, billCounts, calculatedTotal, canProceedToClose, cashLeftForNext, cashSales, cashWithdrawn, changeQty() (+21 more)

### Community 9 - "devDependencies"
Cohesion: 0.11
Nodes (5): LoginRequest, RecipeRequest, RolePermissionRequest, SyncSalesRequest, Illuminate\Foundation\Http\FormRequest

### Community 10 - "Customer"
Cohesion: 0.06
Nodes (30): dexie, dependencies, dexie, pinia, vue, vue-router, devDependencies, playwright (+22 more)

### Community 11 - "SaleSyncTest.php"
Cohesion: 0.09
Nodes (26): catalog, confirmButtonText, emit, errorMessages, extrasPrice, filteredGroups, getGroupSelectedCount(), getMinRequired() (+18 more)

### Community 12 - "UserFactory"
Cohesion: 0.12
Nodes (5): Category, OptionGroup, Product, OptionGroupTest, ProductTest

### Community 13 - "AppServiceProvider"
Cohesion: 0.09
Nodes (9): AuthController, Controller, LoyaltyConfigController, OptionRecipeController, RecipeController, RolePermissionController, SaleController, AdminSaleRequest (+1 more)

### Community 14 - "ExampleTest"
Cohesion: 0.08
Nodes (13): activeCategoryId, cart, catalog, completedSale, editingCartItem, filteredProducts, ingredientStockMap, posErrorMessage (+5 more)

### Community 15 - "0001_01_01_000000_create_users_table.php"
Cohesion: 0.10
Nodes (20): useOrderQueueStore, allergenMap, auth, columns, expandedOrders, formatElapsed(), getCustomerName(), getDisplayOrderNumber() (+12 more)

### Community 16 - "0001_01_01_000001_create_cache_table.php"
Cohesion: 0.22
Nodes (3): CashRegisterSessionController, CashRegisterSession, CashRegisterSessionTest

### Community 17 - "0001_01_01_000002_create_jobs_table.php"
Cohesion: 0.09
Nodes (18): deleteOption(), draggedIndex, dragOverIndex, editingOption, emit, handleAssignSave(), isSavingOrder, isSubmittingOption (+10 more)

### Community 18 - "2026_07_16_000001_create_customers_table.php"
Cohesion: 0.08
Nodes (14): authStore, customFrom, customTo, editingSaleForAdmin, isAdmin, isCashExpenseModalOpen, isOpening, isRetroactiveModalOpen (+6 more)

### Community 19 - "2026_07_16_000002_create_categories_table.php"
Cohesion: 0.09
Nodes (17): activeCategoryName, activeTagName, categoryDropdownRef, compressImage(), deleteProduct(), emit, fileInput, handleImageUpload() (+9 more)

### Community 20 - "2026_07_16_000003_create_products_table.php"
Cohesion: 0.09
Nodes (18): cashNeededAmount, cashReceived, change, currentMethod, customerSearch, emit, handleConfirm(), hasMixedCapability (+10 more)

### Community 21 - "2026_07_16_000004_create_ingredients_table.php"
Cohesion: 0.12
Nodes (6): Request, UserController, UserRequest, UserResource, UserRepository, Illuminate\Http\Response

### Community 22 - "2026_07_16_000005_create_recipes_table.php"
Cohesion: 0.16
Nodes (5): CustomerController, CustomerRequest, CustomerResource, Customer, CustomerRepository

### Community 23 - "2026_07_16_000006_create_sales_table.php"
Cohesion: 0.09
Nodes (21): 1. Autenticación de Cocina, 1. 🎯 Visión General y Arquitectura del Ecosistema, 2. 🌐 Direcciones de la API del Backend (Contrato de Integración), 2. Obtener Lista de Comandas Activas (Cola KDS), 3. Avanzar Estado de la Comanda, 3. 🎨 Especificación UX: Tarjeta KDS en Android (Sin Ventanas Emergentes), 4. 🛠️ Implementación en Código Jetpack Compose (Kotlin), 5. 📋 Plan de Acción de 5 Fases para la App Android (+13 more)

### Community 24 - "2026_07_16_000007_create_sale_payments_table.php"
Cohesion: 0.15
Nodes (4): IngredientCategoryController, IngredientCategoryRequest, IngredientCategory, IngredientCategoryRepository

### Community 25 - "2026_07_16_000008_create_sale_items_table.php"
Cohesion: 0.11
Nodes (12): allergenMap, completedItems, emit, formatElapsed(), getDisplayNumber(), getElapsedMinutes(), getFullCode(), getOrderDailySequence() (+4 more)

### Community 26 - "2026_07_16_000009_create_inventory_movements_table.php"
Cohesion: 0.08
Nodes (8): CashMovement, Sale, SaleItemOption, KdsApiTest, Illuminate\Database\Eloquent\Concerns\HasUuids, Illuminate\Database\Eloquent\Relations\BelongsTo, Illuminate\Database\Eloquent\Relations\HasOne, Illuminate\Database\Eloquent\SoftDeletes

### Community 27 - "2026_07_16_000010_create_loyalty_config_table.php"
Cohesion: 0.17
Nodes (4): Request, ProductController, ProductRequest, ProductResource

### Community 28 - "2026_07_16_000011_create_cash_register_sessions_table.php"
Cohesion: 0.36
Nodes (3): AuditLogController, OrderQueueController, Illuminate\Http\Request

### Community 29 - "2026_07_16_000012_create_delivery_orders_table.php"
Cohesion: 0.29
Nodes (4): AuditLog, LoyaltyConfig, Carbon, SaleSyncService

### Community 30 - "2026_07_16_100837_create_personal_access_tokens_table.php"
Cohesion: 0.11
Nodes (17): devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, vite, private (+9 more)

### Community 31 - "artisan"
Cohesion: 0.17
Nodes (10): useCatalogStore, auth, catalog, email, errorMsg, formErrors, isLoading, password (+2 more)

### Community 32 - "HelloWorld.vue"
Cohesion: 0.13
Nodes (5): IngredientController, IngredientRequest, IngredientResource, SaleResource, Illuminate\Http\Resources\Json\JsonResource

### Community 33 - "app.php"
Cohesion: 0.20
Nodes (4): OptionController, OptionRequest, OptionResource, Option

### Community 34 - "app.php"
Cohesion: 0.18
Nodes (4): OptionGroupController, JsonResponse, OptionGroupRequest, OptionGroupResource

### Community 35 - "cache.php"
Cohesion: 0.15
Nodes (5): ReactivateProducts, DatabaseSeeder, Illuminate\Console\Command, Illuminate\Database\Eloquent\Builder, Illuminate\Database\Seeder

### Community 36 - "database.php"
Cohesion: 0.12
Nodes (16): 1. Visión de Sistema, 2. Stack Tecnológico, 3. Estructura del Monorepo, 4.1. Diagrama Entidad-Relación, 4.2. Decisiones de Diseño de la BD, 4. Modelo de Datos Relacional (MySQL), 5.1. Modelo Offline-First y Sincronización, 5.2. Inventario basado en Recetas (CPP) (+8 more)

### Community 37 - "filesystems.php"
Cohesion: 0.14
Nodes (12): deleteGroup(), editingOptionIndex, emit, handleAssignSave(), linkedProducts, localGroup, newOptName, newOptPrice (+4 more)

### Community 38 - "logging.php"
Cohesion: 0.20
Nodes (4): InventoryMovementController, InventoryMovement, InventoryService, Collection

### Community 39 - "mail.php"
Cohesion: 0.15
Nodes (5): CategoryController, JsonResponse, CategoryRequest, CategoryResource, Illuminate\Http\Resources\Json\AnonymousResourceCollection

### Community 40 - "queue.php"
Cohesion: 0.18
Nodes (8): auth, navItems, route, router, theme, userInitials, userRole, useAuthStore

### Community 41 - "sanctum.php"
Cohesion: 0.17
Nodes (14): canSubmit, currentCash, emit, errorMsg, form, handleClose(), handleSubmit(), isOverdrawn (+6 more)

### Community 42 - "services.php"
Cohesion: 0.40
Nodes (4): db, pruneOldSyncedSales(), syncPendingSales(), ../stores/orderQueue.js

### Community 44 - "index.php"
Cohesion: 0.14
Nodes (13): autoload-dev, psr-4, description, keywords, license, minimum-stability, name, prefer-stable (+5 more)

### Community 45 - "app.js"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 46 - "welcome.blade.php"
Cohesion: 0.17
Nodes (7): allergenTextMap, attrs, auth, emit, hasNextAction, props, ticketText

### Community 47 - "web.php"
Cohesion: 0.20
Nodes (10): emit, expandedCategories, getProductsByCategory(), handleSave(), isCategoryFullySelected(), isCategoryIndeterminate(), props, searchQuery (+2 more)

### Community 48 - "vite.config.js"
Cohesion: 0.15
Nodes (11): authStore, emit, handleConfirmVoid(), isAdmin, isVoiding, sale, showVoidModal, turnoStore (+3 more)

### Community 49 - "vite.config.js"
Cohesion: 0.37
Nodes (14): apiFetch(), alertAction(), confirmAction(), confirmPauseProduct(), deleteCategory(), deleteOptionGroup(), deleteProduct(), fetchData() (+6 more)

### Community 50 - "Plan: canal PedidosYa — arqueo separado, precios por canal y registro desde el POS"
Cohesion: 0.13
Nodes (14): 1. Objetivo, 2. Lo que ya existe (no volver a crearlo), 3. Decisiones tomadas, 4.1 Arqueo (backend), 4.2 Pantallas de arqueo (frontend), 4.3 POS del cajero: selector de canal, 4.4 Validación de precios (backend), 4.5 Datos existentes (+6 more)

### Community 51 - "IngredientRepository"
Cohesion: 0.10
Nodes (5): AppServiceProvider, IngredientRepository, Illuminate\Contracts\Pagination\LengthAwarePaginator, Illuminate\Database\Eloquent\Collection, Illuminate\Support\ServiceProvider

### Community 52 - "ProductPauseModal.vue"
Cohesion: 0.23
Nodes (10): cancel(), confirm(), emit, infoMessage, nextDays, props, resetState(), selectedDate (+2 more)

### Community 53 - "BackupService"
Cohesion: 0.23
Nodes (3): SystemBackupController, BackupService, Symfony\Component\HttpFoundation\BinaryFileResponse

### Community 54 - "static"
Cohesion: 0.21
Nodes (6): bootAuditable(), logAction(), UserFactory, Illuminate\Database\Eloquent\Factories\Factory, self, static

### Community 55 - "Análisis del Funcionamiento del Sistema de Pedidos y Comandas (Ohana Acai V3)"
Cohesion: 0.18
Nodes (10): 1. Arquitectura General (Offline-First), 2.1. Gestión del Carrito (`cart.js`), 2.2. Proceso de Checkout y Generación de la Comanda Local, 2. Flujo de Toma de Pedidos (Frontend), 3. Lógica de Sincronización (Red), 4.1. Recepción y Transaccionalidad, 4.2. Registro y Consolidación, 4. Procesamiento Final (Backend - Laravel) (+2 more)

### Community 56 - "3. ⚠️ Reglas Obligatorias de Compatibilidad (Desarrollo desde Mac)"
Cohesion: 0.18
Nodes (10): 1. Ficha Técnica de la Portátil de Producción (Windows), 2. Flujo de Actualización Automática en la Portátil, 3. ⚠️ Reglas Obligatorias de Compatibilidad (Desarrollo desde Mac), 4. Checklist Rápido antes de hacer `git push` desde la Mac, 🗄️ A. Base de Datos y Migraciones, 📁 B. Manejo de Rutas de Archivos (Path Handling), 🌐 C. Rutas API y Autenticación (Sanctum), 📦 D. Dependencias y Paquetes (Composer y NPM) (+2 more)

### Community 57 - "App.vue"
Cohesion: 0.22
Nodes (9): auth, network, route, theme, useShell, network, ../stores/network.js, useNetworkStore (+1 more)

### Community 58 - "Guía para agentes — Ohana POS"
Cohesion: 0.06
Nodes (33): Al empezar una sesión, Datos del sistema que no son obvios, Guía para agentes — Ohana POS, Mapa rápido, Reglas de trabajo, Trampas conocidas, Verificación obligatoria, Agregado (+25 more)

### Community 59 - "🛣️ Ruta de Desarrollo Sugerida (Por Dependencias)"
Cohesion: 0.20
Nodes (9): Análisis de Módulos y Plan de Desarrollo - Ohana Acai V3, 📦 Detalle Técnico de los Módulos Identificados, Fase 1: Cimientos y Seguridad, Fase 2: Catálogo Base (Lo que se vende), Fase 3: Gestión de Materia Prima (Lo que se consume), Fase 4: El Puente (Recetas), Fase 5: Operación y Cobro, Fase 6: Expansión Comercial (+1 more)

### Community 60 - "AdminProductCard.vue"
Cohesion: 0.22
Nodes (7): assignedGroupsCount, emit, getImageUrl, pauseText, props, tagLabel, resolveImageUrl()

### Community 62 - "Diseño de Fase 1: Cimientos y Seguridad"
Cohesion: 0.22
Nodes (8): 1. Flujo de Login (Híbrido: Online / Offline), 2. Flujo de Matriz de Permisos (RBAC), `AdminUsers.vue`, Diseño de Fase 1: Cimientos y Seguridad, 🗄️ Esquema de Base de Datos (ERD), 🔄 Flujos de Sistema (Sequence Diagrams), 🎨 Interfaz de Usuario (UI), `Login.vue`

### Community 63 - "ProductOptionsModal.vue"
Cohesion: 0.28
Nodes (8): emit, handleSave(), isExcluded(), linkedGroups, localExcluded, props, selectedGroup, toggleExclusion()

### Community 64 - "CashSessionLinkTest"
Cohesion: 0.31
Nodes (3): CashSessionLinkTest, Carbon, Carbon\Carbon

### Community 65 - "ProcessOfflineSaleJob.php"
Cohesion: 0.43
Nodes (6): ProcessOfflineSaleJob, Illuminate\Bus\Queueable, Illuminate\Contracts\Queue\ShouldQueue, Illuminate\Foundation\Bus\Dispatchable, Illuminate\Queue\InteractsWithQueue, Illuminate\Queue\SerializesModels

### Community 66 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 67 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install --ignore-scripts, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 68 - "README.md"
Cohesion: 0.25
Nodes (7): About Laravel, Agentic Development, Code of Conduct, Contributing, Learning Laravel, License, Security Vulnerabilities

### Community 69 - "Closure"
Cohesion: 0.33
Nodes (5): EnsureUserHasRole, EnsureUserIsAdmin, EnsureUserIsSuperAdmin, Closure, Symfony\Component\HttpFoundation\Response

### Community 73 - "ProductRepository"
Cohesion: 0.23
Nodes (3): CatalogController, CategoryRepository, ProductRepository

### Community 74 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 75 - "ProductCard.vue"
Cohesion: 0.33
Nodes (6): displayPrice, emit, getImageUrl, handleAdd(), isAdding, props

### Community 76 - "ReceiptModal.vue"
Cohesion: 0.29
Nodes (5): change, displayOrderNum, emit, orderFullCode, props

### Community 77 - "CashSessionReport.vue"
Cohesion: 0.33
Nodes (4): props, reportData, session, summary

### Community 78 - "PinModal.vue"
Cohesion: 0.47
Nodes (5): emit, handleClose(), handleSubmit(), pin, props

### Community 79 - "🍏 Guía de Conexión desde el Mac al Servidor Windows (Ohana POS)"
Cohesion: 0.33
Nodes (5): 🍏 Guía de Conexión desde el Mac al Servidor Windows (Ohana POS), Notas Técnicas, Opción 1: Estás en tu casa (Conexión Directa por LAN) 🏠, Opción 2: Estás fuera de casa o en el negocio (Vía Cloudflare Tunnel) 🌍, Resumen Rápido

### Community 81 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 82 - "require"
Cohesion: 0.40
Nodes (5): require, laravel/framework, laravel/sanctum, laravel/tinker, php

### Community 83 - "AssignOptionGroupsModal.vue"
Cohesion: 0.50
Nodes (4): emit, handleConfirm(), localSelected, props

### Community 85 - "index.js"
Cohesion: 0.40
Nodes (4): app, updateSW, router, routes

### Community 86 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 88 - "capture_dark_mode.cjs"
Cohesion: 0.50
Nodes (3): { chromium }, fs, path

### Community 89 - "capture_dark_pos.cjs"
Cohesion: 0.50
Nodes (3): { chromium }, fs, path

### Community 91 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 166 - "theme.js"
Cohesion: 0.70
Nodes (3): useThemeStore, applyHardwareOptimizations(), detectHardwareProfile()

## Knowledge Gaps
- **558 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+553 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **19 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `apiFetch()` connect `vite.config.js` to `Illuminate\Database\Eloquent\Factories\HasFactory`, `Pos.vue`, `Product`, `filesystems.php`, `Illuminate\Foundation\Http\FormRequest`, `package.json`, `queue.php`, `services.php`, `0001_01_01_000002_create_jobs_table.php`?**
  _High betweenness centrality (0.026) - this node is a cross-community bridge._
- **Why does `User` connect `Category` to `CashSessionLinkTest`, `composer.json`, `cache.php`, `SalePricingTest`, `session.php`, `UserFactory`, `AppServiceProvider`, `SaleSyncTest`, `0001_01_01_000001_create_cache_table.php`, `IngredientRepository`, `2026_07_16_000004_create_ingredients_table.php`, `2026_07_16_000009_create_inventory_movements_table.php`, `AdminSaleManagementTest`?**
  _High betweenness centrality (0.016) - this node is a cross-community bridge._
- **Why does `Product` connect `UserFactory` to `CashSessionLinkTest`, `Category`, `composer.json`, `cache.php`, `SalePricingTest`, `ProductRepository`, `session.php`, `SaleSyncTest`, `SalePricingService`, `IngredientRepository`, `2026_07_16_000009_create_inventory_movements_table.php`, `AdminSaleManagementTest`?**
  _High betweenness centrality (0.013) - this node is a cross-community bridge._
- **Are the 16 inferred relationships involving `User` (e.g. with `.login()` and `.run()`) actually correct?**
  _`User` has 16 INFERRED edges - model-reasoned connections that need verification._
- **Are the 16 inferred relationships involving `Sale` (e.g. with `.calculateSessionStats()` and `.store()`) actually correct?**
  _`Sale` has 16 INFERRED edges - model-reasoned connections that need verification._
- **Are the 13 inferred relationships involving `Product` (e.g. with `.handle()` and `.run()`) actually correct?**
  _`Product` has 13 INFERRED edges - model-reasoned connections that need verification._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _558 weakly-connected nodes found - possible documentation gaps or missing edges._