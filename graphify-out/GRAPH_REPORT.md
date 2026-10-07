# Graph Report - Sistema de Ventas  (2026-10-07)

## Corpus Check
- 266 files · ~642,945 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 2023 nodes · 2877 edges · 280 communities (161 shown, 119 thin omitted)
- Extraction: 94% EXTRACTED · 6% INFERRED · 0% AMBIGUOUS · INFERRED: 163 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `96cba95c`
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
- vigilante-actualizaciones.ps1
- extra
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
- 2026_07_17_200212_create_audit_logs_table.php
- 2026_07_19_000001_create_ingredient_categories_table.php
- 2026_07_19_000002_add_category_and_type_to_ingredients_table.php
- HelloWorld.vue
- test_delete.php
- cart.js
- theme.js
- SalePayment
- CashSessionResolver.php
- audit-contrast.cjs
- 2026_10_04_000001_add_cash_register_session_id_to_sales_table.php
- CartItem.vue
- CLAUDE.md
- iniciar-pos.sh
- JsonResponse
- Request
- JsonResponse
- Request
- JsonResponse
- AnonymousResourceCollection
- JsonResponse
- Response
- AnonymousResourceCollection
- Response
- JsonResponse
- AnonymousResourceCollection
- Request
- Response
- JsonResponse
- JsonResponse
- Request
- JsonResponse
- Request
- AnonymousResourceCollection
- Response
- AnonymousResourceCollection
- JsonResponse
- Request
- Response
- JsonResponse
- Request
- JsonResponse
- Request
- AnonymousResourceCollection
- Request
- Response
- JsonResponse
- JsonResponse
- JsonResponse
- Request
- JsonResponse
- Request
- AnonymousResourceCollection
- Request
- Response
- Closure
- Request
- Response
- Closure
- Request
- Response
- Closure
- Request
- Response
- Request
- Request
- Request
- Request
- Request
- Request
- Request
- BelongsTo
- BelongsTo
- HasMany
- BelongsTo
- Builder
- HasMany
- HasMany
- BelongsTo
- BelongsTo
- HasMany
- HasMany
- BelongsTo
- BelongsTo
- BelongsTo
- BelongsTo
- HasMany
- BelongsTo
- BelongsTo
- HasMany
- Collection
- Collection
- Collection
- LengthAwarePaginator
- Collection
- LengthAwarePaginator
- LengthAwarePaginator
- Collection
- Carbon

## God Nodes (most connected - your core abstractions)
1. `apiFetch()` - 49 edges
2. `Sale` - 45 edges
3. `Product` - 44 edges
4. `User` - 44 edges
5. `TestCase` - 40 edges
6. `Category` - 37 edges
7. `CashRegisterSession` - 28 edges
8. `Controller` - 23 edges
9. `SaleSyncService` - 21 edges
10. `SaleItem` - 20 edges

## Surprising Connections (you probably didn't know these)
- `logAction()` --calls--> `AuditLog`  [INFERRED]
  backend/app/Traits/Auditable.php → backend/app/Models/AuditLog.php
- `loadUsers()` --calls--> `apiFetch()`  [EXTRACTED]
  frontend/src/components/AdminSaleEditor.vue → frontend/src/services/api.js
- `fetchPermissions()` --calls--> `apiFetch()`  [EXTRACTED]
  frontend/src/views/AdminUsers.vue → frontend/src/services/api.js
- `fetchUsers()` --calls--> `apiFetch()`  [EXTRACTED]
  frontend/src/views/AuditLogs.vue → frontend/src/services/api.js
- `AuthController` --inherits--> `Controller`  [EXTRACTED]
  backend/app/Http/Controllers/AuthController.php → backend/app/Http/Controllers/Controller.php

## Import Cycles
- None detected.

## Communities (280 total, 119 thin omitted)

### Community 0 - "Illuminate\Database\Eloquent\Factories\HasFactory"
Cohesion: 0.07
Nodes (41): addProductToSale(), autoSyncPayments(), calculatedSubtotal, calculatedTotal, catalogStore, emit, errorMessage, filteredAvailableProducts (+33 more)

### Community 1 - "Category"
Cohesion: 0.10
Nodes (6): PedidosYaPricingTest, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, Laravel\Sanctum\HasApiTokens, User, SystemBackupTest

### Community 2 - "Pos.vue"
Cohesion: 0.05
Nodes (34): emit, parsedChanges, props, emit, errorMessage, handleDownload(), isDownloading, onClose() (+26 more)

### Community 3 - "composer.json"
Cohesion: 0.21
Nodes (3): SaleItem, PedidosYaPriceOverrideTest, Illuminate\Database\Eloquent\Relations\BelongsTo

### Community 4 - "Product"
Cohesion: 0.06
Nodes (36): activeTab, alertAction(), alertMessage, authStore, confirmAction(), confirmCallback, confirmMessage, decidedCount (+28 more)

### Community 5 - "Illuminate\Foundation\Http\FormRequest"
Cohesion: 0.05
Nodes (36): activeCategory, activeCategoryProducts, activeOptionGroup, activeProductForModal, activeProductForPause, activeTab, alertMessage, categories (+28 more)

### Community 6 - "scripts"
Cohesion: 0.05
Nodes (38): activeTab, authStore, cardSales, cashExpenses, cashSales, closedReport, currentSession, currentStats (+30 more)

### Community 7 - "package.json"
Cohesion: 0.07
Nodes (31): alertAction(), alertMessage, categories, categoryForm, confirmAction(), confirmCallback, confirmMessage, deleteCategory() (+23 more)

### Community 8 - "Illuminate\Database\Eloquent\Relations\HasMany"
Cohesion: 0.07
Nodes (29): authStore, billCounts, calculatedTotal, canProceedToClose, cashLeftForNext, cashSales, cashWithdrawn, changeQty() (+21 more)

### Community 9 - "devDependencies"
Cohesion: 0.20
Nodes (3): SyncSalesRequest, Illuminate\Foundation\Http\FormRequest, RolePermissionRequest

### Community 10 - "Customer"
Cohesion: 0.06
Nodes (30): dexie, vite, pinia, playwright, @playwright/test, dependencies, dexie, pinia (+22 more)

### Community 11 - "SaleSyncTest.php"
Cohesion: 0.08
Nodes (28): basePrice, catalog, confirmButtonText, emit, errorMessages, extrasPrice, filteredGroups, getGroupSelectedCount() (+20 more)

### Community 12 - "UserFactory"
Cohesion: 0.09
Nodes (5): Product, Illuminate\Database\Eloquent\Relations\HasMany, Category, OptionGroup, ProductTest

### Community 13 - "AppServiceProvider"
Cohesion: 0.17
Nodes (3): AdminSaleRequest, Carbon\Carbon, SaleController

### Community 14 - "ExampleTest"
Cohesion: 0.06
Nodes (17): activeCategoryId, cart, catalog, completedSale, editingCartItem, filteredProducts, ingredientStockMap, isPedidosYa (+9 more)

### Community 15 - "0001_01_01_000000_create_users_table.php"
Cohesion: 0.10
Nodes (20): useOrderQueueStore, allergenMap, auth, columns, expandedOrders, formatElapsed(), getCustomerName(), getDisplayOrderNumber() (+12 more)

### Community 16 - "0001_01_01_000001_create_cache_table.php"
Cohesion: 0.08
Nodes (11): CashRegisterSessionController, PedidosYaPricingController, Illuminate\Http\JsonResponse, Illuminate\Http\Request, Symfony\Component\HttpFoundation\StreamedResponse, AuditLogController, Controller, KdsController (+3 more)

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
Cohesion: 0.08
Nodes (20): cashNeededAmount, cashReceived, change, currentMethod, customerSearch, emit, handleConfirm(), hasMixedCapability (+12 more)

### Community 21 - "2026_07_16_000004_create_ingredients_table.php"
Cohesion: 0.14
Nodes (10): App\Traits\Auditable, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\SoftDeletes, Customer, DeliveryOrder, IngredientCategory, Option (+2 more)

### Community 22 - "2026_07_16_000005_create_recipes_table.php"
Cohesion: 0.10
Nodes (9): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, CategoryTest, OptionGroupTest, ExampleTest, OrderQueueTest, UserManagementTest, TestCase (+1 more)

### Community 23 - "2026_07_16_000006_create_sales_table.php"
Cohesion: 0.10
Nodes (23): applyPercent(), bulkPercent, bulkScope, changedOptions, changedProducts, changesCount, diffLabel(), emit (+15 more)

### Community 24 - "2026_07_16_000007_create_sale_payments_table.php"
Cohesion: 0.11
Nodes (4): IngredientController, IngredientRequest, IngredientResource, IngredientRepository

### Community 25 - "2026_07_16_000008_create_sale_items_table.php"
Cohesion: 0.11
Nodes (12): allergenMap, completedItems, emit, formatElapsed(), getDisplayNumber(), getElapsedMinutes(), getFullCode(), getOrderDailySequence() (+4 more)

### Community 26 - "2026_07_16_000009_create_inventory_movements_table.php"
Cohesion: 0.14
Nodes (4): Illuminate\Database\Eloquent\Concerns\HasUuids, Illuminate\Database\Eloquent\Relations\HasOne, Sale, CashSessionLinkTest

### Community 27 - "2026_07_16_000010_create_loyalty_config_table.php"
Cohesion: 0.20
Nodes (3): ProductRequest, ProductResource, ProductController

### Community 28 - "2026_07_16_000011_create_cash_register_sessions_table.php"
Cohesion: 0.12
Nodes (4): UserController, UserRequest, UserResource, UserRepository

### Community 29 - "2026_07_16_000012_create_delivery_orders_table.php"
Cohesion: 0.27
Nodes (3): Carbon, SaleSyncService, AuditLog

### Community 30 - "2026_07_16_100837_create_personal_access_tokens_table.php"
Cohesion: 0.11
Nodes (17): vite, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, devDependencies, concurrently, laravel-vite-plugin (+9 more)

### Community 31 - "artisan"
Cohesion: 0.17
Nodes (10): useCatalogStore, auth, catalog, email, errorMsg, formErrors, isLoading, password (+2 more)

### Community 33 - "app.php"
Cohesion: 0.09
Nodes (21): 1. Autenticación de Cocina, 1. 🎯 Visión General y Arquitectura del Ecosistema, 2. 🌐 Direcciones de la API del Backend (Contrato de Integración), 2. Obtener Lista de Comandas Activas (Cola KDS), 3. Avanzar Estado de la Comanda, 3. 🎨 Especificación UX: Tarjeta KDS en Android (Sin Ventanas Emergentes), 4. 🛠️ Implementación en Código Jetpack Compose (Kotlin), 5. 📋 Plan de Acción de 5 Fases para la App Android (+13 more)

### Community 34 - "app.php"
Cohesion: 0.17
Nodes (3): OptionGroupController, OptionGroupRequest, OptionGroupResource

### Community 35 - "cache.php"
Cohesion: 0.19
Nodes (3): Illuminate\Database\Eloquent\Builder, ProductRepository, KdsApiTest

### Community 36 - "database.php"
Cohesion: 0.12
Nodes (4): Illuminate\Database\Seeder, Recipe, DatabaseSeeder, AdminSaleManagementTest

### Community 37 - "filesystems.php"
Cohesion: 0.14
Nodes (12): deleteGroup(), editingOptionIndex, emit, handleAssignSave(), linkedProducts, localGroup, newOptName, newOptPrice (+4 more)

### Community 38 - "logging.php"
Cohesion: 0.15
Nodes (4): CatalogController, CategoryController, CategoryRequest, CategoryResource

### Community 39 - "mail.php"
Cohesion: 0.13
Nodes (3): IngredientCategoryController, IngredientCategoryRequest, IngredientCategoryRepository

### Community 40 - "queue.php"
Cohesion: 0.18
Nodes (8): auth, navItems, route, router, theme, userInitials, userRole, useAuthStore

### Community 41 - "sanctum.php"
Cohesion: 0.17
Nodes (14): canSubmit, currentCash, emit, errorMsg, form, handleClose(), handleSubmit(), isOverdrawn (+6 more)

### Community 42 - "services.php"
Cohesion: 0.40
Nodes (4): db, pruneOldSyncedSales(), syncPendingSales(), ../stores/orderQueue.js

### Community 43 - "session.php"
Cohesion: 0.12
Nodes (16): 1. Visión de Sistema, 2. Stack Tecnológico, 3. Estructura del Monorepo, 4.1. Diagrama Entidad-Relación, 4.2. Decisiones de Diseño de la BD, 4. Modelo de Datos Relacional (MySQL), 5.1. Modelo Offline-First y Sincronización, 5.2. Inventario basado en Recetas (CPP) (+8 more)

### Community 44 - "index.php"
Cohesion: 0.67
Nodes (3): framework, laravel, keywords

### Community 45 - "app.js"
Cohesion: 0.14
Nodes (14): Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, Illuminate\\Foundation\\ComposerScripts::prePackageUninstall, npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74\" \"php artisan serve\" \"php artisan queue:listen --tries=1 --timeout=0\" \"php artisan pail --timeout=0\" \"npm run dev\" --names=server,queue,logs,vite --kill-others, @php artisan config:clear --ansi @no_additional_args, @php artisan package:discover --ansi, @php artisan test, @php artisan vendor:publish --tag=laravel-assets --ansi --force (+6 more)

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
Cohesion: 0.19
Nodes (3): CustomerController, CustomerRequest, CustomerResource

### Community 51 - "IngredientRepository"
Cohesion: 0.15
Nodes (4): Illuminate\Support\ServiceProvider, AppServiceProvider, CustomerRepository, SaleRepository

### Community 52 - "ProductPauseModal.vue"
Cohesion: 0.23
Nodes (10): cancel(), confirm(), emit, infoMessage, nextDays, props, resetState(), selectedDate (+2 more)

### Community 53 - "BackupService"
Cohesion: 0.23
Nodes (3): Symfony\Component\HttpFoundation\BinaryFileResponse, SystemBackupController, BackupService

### Community 54 - "static"
Cohesion: 0.21
Nodes (6): Illuminate\Database\Eloquent\Factories\Factory, self, static, bootAuditable(), logAction(), UserFactory

### Community 55 - "Análisis del Funcionamiento del Sistema de Pedidos y Comandas (Ohana Acai V3)"
Cohesion: 0.13
Nodes (14): 1. Objetivo, 2. Lo que ya existe (no volver a crearlo), 3. Decisiones tomadas, 4.1 Arqueo (backend), 4.2 Pantallas de arqueo (frontend), 4.3 POS del cajero: selector de canal, 4.4 Validación de precios (backend), 4.5 Datos existentes (+6 more)

### Community 56 - "3. ⚠️ Reglas Obligatorias de Compatibilidad (Desarrollo desde Mac)"
Cohesion: 0.15
Nodes (13): apply(), emit, error, finalReason, hasOverride, isTariffPrice, newPrice, otherReason (+5 more)

### Community 57 - "App.vue"
Cohesion: 0.22
Nodes (9): auth, network, route, theme, useShell, network, ../stores/network.js, useNetworkStore (+1 more)

### Community 58 - "Guía para agentes — Ohana POS"
Cohesion: 0.05
Nodes (36): Al empezar una sesión, Datos del sistema que no son obvios, Guía para agentes — Ohana POS, Mapa rápido, Reglas de trabajo, Trampas conocidas, Verificación obligatoria, Agregado (+28 more)

### Community 59 - "🛣️ Ruta de Desarrollo Sugerida (Por Dependencias)"
Cohesion: 0.14
Nodes (13): autoload-dev, psr-4, description, extra, laravel, dont-discover, license, minimum-stability (+5 more)

### Community 60 - "AdminProductCard.vue"
Cohesion: 0.22
Nodes (7): assignedGroupsCount, emit, getImageUrl, pauseText, props, tagLabel, resolveImageUrl()

### Community 61 - "AdminSaleManagementTest"
Cohesion: 0.23
Nodes (3): OptionController, OptionRequest, OptionResource

### Community 63 - "ProductOptionsModal.vue"
Cohesion: 0.28
Nodes (8): emit, handleSave(), isExcluded(), linkedGroups, localExcluded, props, selectedGroup, toggleExclusion()

### Community 64 - "CashSessionLinkTest"
Cohesion: 0.18
Nodes (10): 1. Arquitectura General (Offline-First), 2.1. Gestión del Carrito (`cart.js`), 2.2. Proceso de Checkout y Generación de la Comanda Local, 2. Flujo de Toma de Pedidos (Frontend), 3. Lógica de Sincronización (Red), 4.1. Recepción y Transaccionalidad, 4.2. Registro y Consolidación, 4. Procesamiento Final (Backend - Laravel) (+2 more)

### Community 65 - "ProcessOfflineSaleJob.php"
Cohesion: 0.24
Nodes (7): Illuminate\Bus\Queueable, Illuminate\Contracts\Queue\ShouldQueue, Illuminate\Foundation\Bus\Dispatchable, Illuminate\Queue\InteractsWithQueue, Illuminate\Queue\SerializesModels, ProcessOfflineSaleJob, AuditLogger

### Community 66 - "require-dev"
Cohesion: 0.18
Nodes (10): 1. Ficha Técnica de la Portátil de Producción (Windows), 2. Flujo de Actualización Automática en la Portátil, 3. ⚠️ Reglas Obligatorias de Compatibilidad (Desarrollo desde Mac), 4. Checklist Rápido antes de hacer `git push` desde la Mac, 🗄️ A. Base de Datos y Migraciones, 📁 B. Manejo de Rutas de Archivos (Path Handling), 🌐 C. Rutas API y Autenticación (Sanctum), 📦 D. Dependencias y Paquetes (Composer y NPM) (+2 more)

### Community 67 - "setup"
Cohesion: 0.25
Nodes (8): composer install, npm install --ignore-scripts, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\, post-root-package-install, setup

### Community 68 - "README.md"
Cohesion: 0.18
Nodes (10): 🚀 Arquitectura y Tecnologías, Backend (RESTful API), Configuración del Backend (Laravel), Configuración del Frontend (Vue.js), 📦 Estructura del Proyecto, Frontend (SPA / PWA), 🛠 Instalación y Configuración Local, Ohana Acai V3 - Sistema POS y Gestión (+2 more)

### Community 69 - "Closure"
Cohesion: 0.27
Nodes (9): getActiveOptionPrice(), priceOf(), hasDeliveryPrice(), isAvailableOnPedidosYa(), missingDeliveryPrice(), optionPrice(), productPrice(), handleAddToCart() (+1 more)

### Community 72 - "SalePricingTest"
Cohesion: 0.20
Nodes (9): Análisis de Módulos y Plan de Desarrollo - Ohana Acai V3, 📦 Detalle Técnico de los Módulos Identificados, Fase 1: Cimientos y Seguridad, Fase 2: Catálogo Base (Lo que se vende), Fase 3: Gestión de Materia Prima (Lo que se consume), Fase 4: El Puente (Recetas), Fase 5: Operación y Cobro, Fase 6: Expansión Comercial (+1 more)

### Community 75 - "ProductCard.vue"
Cohesion: 0.29
Nodes (7): displayPrice, emit, getImageUrl, handleAdd(), isAdding, props, usesLocalPrice

### Community 76 - "ReceiptModal.vue"
Cohesion: 0.29
Nodes (5): change, displayOrderNum, emit, orderFullCode, props

### Community 77 - "CashSessionReport.vue"
Cohesion: 0.33
Nodes (4): props, reportData, session, summary

### Community 78 - "PinModal.vue"
Cohesion: 0.47
Nodes (5): emit, handleClose(), handleSubmit(), pin, props

### Community 82 - "require"
Cohesion: 0.22
Nodes (8): 1. Flujo de Login (Híbrido: Online / Offline), 2. Flujo de Matriz de Permisos (RBAC), `AdminUsers.vue`, Diseño de Fase 1: Cimientos y Seguridad, 🗄️ Esquema de Base de Datos (ERD), 🔄 Flujos de Sistema (Sequence Diagrams), 🎨 Interfaz de Usuario (UI), `Login.vue`

### Community 83 - "AssignOptionGroupsModal.vue"
Cohesion: 0.50
Nodes (4): emit, handleConfirm(), localSelected, props

### Community 84 - "InventoryMovementRequest"
Cohesion: 0.25
Nodes (8): getLocalDateStr(), handleExpenseSaved(), handleOpenSession(), handleSaleEdited(), handleSaleVoided(), handleSavedSale(), handleSessionClosed(), loadDayData()

### Community 85 - "index.js"
Cohesion: 0.40
Nodes (4): app, updateSW, router, routes

### Community 86 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\, post-create-project-cmd

### Community 88 - "capture_dark_mode.cjs"
Cohesion: 0.50
Nodes (3): { chromium }, fs, path

### Community 89 - "capture_dark_pos.cjs"
Cohesion: 0.50
Nodes (3): { chromium }, fs, path

### Community 93 - "0001_01_01_000001_create_cache_table.php"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 94 - "0001_01_01_000002_create_jobs_table.php"
Cohesion: 0.25
Nodes (7): About Laravel, Agentic Development, Code of Conduct, Contributing, Learning Laravel, License, Security Vulnerabilities

### Community 97 - "2026_07_16_000003_create_products_table.php"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 102 - "2026_07_16_000008_create_sale_items_table.php"
Cohesion: 0.33
Nodes (5): 🍏 Guía de Conexión desde el Mac al Servidor Windows (Ohana POS), Notas Técnicas, Opción 1: Estás en tu casa (Conexión Directa por LAN) 🏠, Opción 2: Estás fuera de casa o en el negocio (Vía Cloudflare Tunnel) 🌍, Resumen Rápido

### Community 103 - "2026_07_16_000009_create_inventory_movements_table.php"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 104 - "2026_07_16_000010_create_loyalty_config_table.php"
Cohesion: 0.40
Nodes (5): require, laravel/framework, laravel/sanctum, laravel/tinker, php

### Community 166 - "theme.js"
Cohesion: 0.70
Nodes (3): useThemeStore, applyHardwareOptimizations(), detectHardwareProfile()

## Knowledge Gaps
- **605 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+600 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **119 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Controller` connect `0001_01_01_000001_create_cache_table.php` to `2026_07_16_000002_create_categories_table.php`, `app.php`, `2026_07_16_000005_create_recipes_table.php`, `logging.php`, `KdsController`, `mail.php`, `SaleRepository`, `config`, `AppServiceProvider`, `Plan: canal PedidosYa — arqueo separado, precios por canal y registro desde el POS`, `BackupService`, `2026_07_16_000007_create_sale_payments_table.php`, `vigilante-actualizaciones.ps1`, `2026_07_16_000010_create_loyalty_config_table.php`, `2026_07_16_000011_create_cash_register_sessions_table.php`, `AdminSaleManagementTest`?**
  _High betweenness centrality (0.027) - this node is a cross-community bridge._
- **Why does `apiFetch()` connect `vite.config.js` to `Illuminate\Database\Eloquent\Factories\HasFactory`, `Pos.vue`, `Product`, `filesystems.php`, `Illuminate\Foundation\Http\FormRequest`, `package.json`, `queue.php`, `services.php`, `0001_01_01_000002_create_jobs_table.php`, `2026_07_16_000006_create_sales_table.php`?**
  _High betweenness centrality (0.023) - this node is a cross-community bridge._
- **Why does `Product` connect `UserFactory` to `Category`, `composer.json`, `cache.php`, `ProductRepository`, `2026_07_16_000012_create_delivery_orders_table.php`, `AppServiceProvider`, `🍏 Guía de Conexión desde el Mac al Servidor Windows (Ohana POS)`, `0001_01_01_000001_create_cache_table.php`, `SalePricingService`, `2026_07_16_000004_create_ingredients_table.php`, `2026_07_16_000012_create_delivery_orders_table.php`, `2026_07_16_000001_create_customers_table.php`?**
  _High betweenness centrality (0.018) - this node is a cross-community bridge._
- **Are the 17 inferred relationships involving `Sale` (e.g. with `.calculateSessionStats()` and `.store()`) actually correct?**
  _`Sale` has 17 INFERRED edges - model-reasoned connections that need verification._
- **Are the 14 inferred relationships involving `Product` (e.g. with `.update()` and `.handle()`) actually correct?**
  _`Product` has 14 INFERRED edges - model-reasoned connections that need verification._
- **Are the 16 inferred relationships involving `User` (e.g. with `.login()` and `.run()`) actually correct?**
  _`User` has 16 INFERRED edges - model-reasoned connections that need verification._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _605 weakly-connected nodes found - possible documentation gaps or missing edges._