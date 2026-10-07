# Graph Report - Sistema de Ventas  (2026-10-07)

## Corpus Check
- 289 files · ~431,131 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 2156 nodes · 3706 edges · 192 communities (172 shown, 20 thin omitted)
- Extraction: 95% EXTRACTED · 5% INFERRED · 0% AMBIGUOUS · INFERRED: 174 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `b81d9608`
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
- 2026_07_16_000010_create_loyalty_config_table.php
- 2026_07_16_000012_create_delivery_orders_table.php
- HelloWorld.vue
- index.php
- welcome.blade.php
- web.php
- test_delete.php
- cart.js
- theme.js
- CashSessionResolver.php
- audit-contrast.cjs
- CartItem.vue

## God Nodes (most connected - your core abstractions)
1. `User` - 86 edges
2. `Product` - 74 edges
3. `Sale` - 66 edges
4. `apiFetch()` - 63 edges
5. `TestCase` - 46 edges
6. `Category` - 45 edges
7. `CashRegisterSession` - 33 edges
8. `Ingredient` - 32 edges
9. `Option` - 29 edges
10. `OptionGroup` - 28 edges

## Surprising Connections (you probably didn't know these)
- `logAction()` --calls--> `AuditLog`  [INFERRED]
  backend/app/Traits/Auditable.php → backend/app/Models/AuditLog.php
- `loadUsers()` --calls--> `apiFetch()`  [EXTRACTED]
  frontend/src/components/AdminSaleEditor.vue → frontend/src/services/api.js
- `fetchCategories()` --calls--> `apiFetch()`  [EXTRACTED]
  frontend/src/components/CashExpenseModal.vue → frontend/src/services/api.js
- `deleteExpense()` --calls--> `confirm()`  [INFERRED]
  frontend/src/views/Finance.vue → frontend/src/components/Menu/ProductPauseModal.vue
- `deleteRecurring()` --calls--> `confirm()`  [INFERRED]
  frontend/src/views/Finance.vue → frontend/src/components/Menu/ProductPauseModal.vue

## Import Cycles
- None detected.

## Communities (192 total, 20 thin omitted)

### Community 0 - "Illuminate\Database\Eloquent\Factories\HasFactory"
Cohesion: 0.07
Nodes (41): addProductToSale(), autoSyncPayments(), calculatedSubtotal, calculatedTotal, catalogStore, emit, errorMessage, filteredAvailableProducts (+33 more)

### Community 1 - "Category"
Cohesion: 0.05
Nodes (18): User, CategoryTest, OptionGroupTest, ExampleTest, FinanceAccessTest, FinancePnlCalculationTest, OrderQueueTest, PedidosYaPricingTest (+10 more)

### Community 2 - "Pos.vue"
Cohesion: 0.10
Nodes (14): applyFilters(), changePage(), clearFilters(), fetchLogs(), fetchUsers(), filters, logs, pagination (+6 more)

### Community 4 - "Product"
Cohesion: 0.06
Nodes (36): activeTab, alertAction(), alertMessage, authStore, confirmAction(), confirmCallback, confirmMessage, decidedCount (+28 more)

### Community 5 - "Illuminate\Foundation\Http\FormRequest"
Cohesion: 0.05
Nodes (36): activeCategory, activeCategoryProducts, activeOptionGroup, activeProductForModal, activeProductForPause, activeTab, alertMessage, categories (+28 more)

### Community 6 - "scripts"
Cohesion: 0.04
Nodes (48): activeTab, authStore, cardSales, cashExpenses, cashSales, closedReport, currentSession, currentStats (+40 more)

### Community 7 - "package.json"
Cohesion: 0.07
Nodes (31): alertAction(), alertMessage, categories, categoryForm, confirmAction(), confirmCallback, confirmMessage, deleteCategory() (+23 more)

### Community 8 - "Illuminate\Database\Eloquent\Relations\HasMany"
Cohesion: 0.07
Nodes (29): authStore, billCounts, calculatedTotal, canProceedToClose, cashLeftForNext, cashSales, cashWithdrawn, changeQty() (+21 more)

### Community 9 - "devDependencies"
Cohesion: 0.07
Nodes (8): AdminSaleRequest, ExpenseCategoryRequest, FinanceExpenseRequest, LoginRequest, RecipeRequest, RolePermissionRequest, SyncSalesRequest, Illuminate\Foundation\Http\FormRequest

### Community 10 - "Customer"
Cohesion: 0.06
Nodes (30): dexie, dependencies, dexie, pinia, vue, vue-router, devDependencies, playwright (+22 more)

### Community 11 - "SaleSyncTest.php"
Cohesion: 0.08
Nodes (29): basePrice, catalog, confirmButtonText, emit, errorMessages, extrasPrice, filteredGroups, getGroupSelectedCount() (+21 more)

### Community 12 - "UserFactory"
Cohesion: 0.07
Nodes (8): CatalogController, Category, Product, CategoryRepository, ProductRepository, Carbon, ProductTest, Illuminate\Database\Eloquent\Builder

### Community 13 - "AppServiceProvider"
Cohesion: 0.05
Nodes (35): activeTab, categories, categoriesLoading, categoryForm, categoryFormError, categoryFormSaving, currentDate, editingExpenseId (+27 more)

### Community 14 - "ExampleTest"
Cohesion: 0.06
Nodes (17): activeCategoryId, cart, catalog, completedSale, editingCartItem, filteredProducts, ingredientStockMap, isPedidosYa (+9 more)

### Community 15 - "0001_01_01_000000_create_users_table.php"
Cohesion: 0.10
Nodes (19): allergenMap, auth, columns, expandedOrders, formatElapsed(), getCustomerName(), getDisplayOrderNumber(), getElapsedMinutes() (+11 more)

### Community 16 - "0001_01_01_000001_create_cache_table.php"
Cohesion: 0.26
Nodes (4): CashRegisterSessionController, CashRegisterSession, CashSessionResolver, Carbon\CarbonInterface

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
Cohesion: 0.07
Nodes (11): DeliveryOrder, Option, OptionRecipe, PlatformCatalogMapping, ProductVariant, RolePermission, SaleItemOption, Illuminate\Database\Eloquent\Factories\HasFactory (+3 more)

### Community 22 - "2026_07_16_000005_create_recipes_table.php"
Cohesion: 0.05
Nodes (13): IngredientCategoryController, InventoryMovementController, IngredientCategoryRequest, InventoryMovementRequest, Ingredient, IngredientCategory, InventoryMovement, IngredientCategoryRepository (+5 more)

### Community 23 - "2026_07_16_000006_create_sales_table.php"
Cohesion: 0.10
Nodes (23): applyPercent(), bulkPercent, bulkScope, changedOptions, changedProducts, changesCount, diffLabel(), emit (+15 more)

### Community 24 - "2026_07_16_000007_create_sale_payments_table.php"
Cohesion: 0.12
Nodes (5): Request, UserController, UserRequest, UserResource, UserRepository

### Community 25 - "2026_07_16_000008_create_sale_items_table.php"
Cohesion: 0.11
Nodes (12): allergenMap, completedItems, emit, formatElapsed(), getDisplayNumber(), getElapsedMinutes(), getFullCode(), getOrderDailySequence() (+4 more)

### Community 27 - "2026_07_16_000010_create_loyalty_config_table.php"
Cohesion: 0.14
Nodes (5): Request, ProductController, ProductRequest, ProductResource, Illuminate\Http\Response

### Community 28 - "2026_07_16_000011_create_cash_register_sessions_table.php"
Cohesion: 0.16
Nodes (4): CategoryController, JsonResponse, CategoryRequest, CategoryResource

### Community 29 - "2026_07_16_000012_create_delivery_orders_table.php"
Cohesion: 0.05
Nodes (24): ProcessOfflineSaleJob, AuditLog, LoyaltyConfig, Sale, SaleRepository, AuditLogger, SalePricingService, Carbon (+16 more)

### Community 30 - "2026_07_16_100837_create_personal_access_tokens_table.php"
Cohesion: 0.11
Nodes (17): devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, vite, private (+9 more)

### Community 31 - "artisan"
Cohesion: 0.18
Nodes (9): auth, catalog, email, errorMsg, formErrors, isLoading, password, router (+1 more)

### Community 32 - "HelloWorld.vue"
Cohesion: 0.14
Nodes (5): IngredientController, IngredientRequest, IngredientResource, SaleResource, Illuminate\Http\Resources\Json\JsonResource

### Community 33 - "app.php"
Cohesion: 0.15
Nodes (5): CustomerController, CustomerRequest, CustomerResource, Customer, CustomerRepository

### Community 34 - "app.php"
Cohesion: 0.19
Nodes (5): OptionGroupController, JsonResponse, OptionGroupRequest, OptionGroupResource, OptionGroup

### Community 35 - "cache.php"
Cohesion: 0.09
Nodes (21): 1. Autenticación de Cocina, 1. 🎯 Visión General y Arquitectura del Ecosistema, 2. 🌐 Direcciones de la API del Backend (Contrato de Integración), 2. Obtener Lista de Comandas Activas (Cola KDS), 3. Avanzar Estado de la Comanda, 3. 🎨 Especificación UX: Tarjeta KDS en Android (Sin Ventanas Emergentes), 4. 🛠️ Implementación en Código Jetpack Compose (Kotlin), 5. 📋 Plan de Acción de 5 Fases para la App Android (+13 more)

### Community 36 - "database.php"
Cohesion: 0.22
Nodes (4): Recipe, DatabaseSeeder, SaleSyncTest, Illuminate\Database\Seeder

### Community 37 - "filesystems.php"
Cohesion: 0.14
Nodes (12): deleteGroup(), editingOptionIndex, emit, handleAssignSave(), linkedProducts, localGroup, newOptName, newOptPrice (+4 more)

### Community 38 - "logging.php"
Cohesion: 0.12
Nodes (17): 10. Análisis de nodos e impacto (grafo `graphify-out/`, 07/10), 11. Fases, 12. Tests, 13. Decisiones (con propuesta), 14. Fuera de alcance, 1. Objetivo, 2. Cómo opera Ohana (datos del dueño), 3. Análisis del material Swiss Burger (resumen) (+9 more)

### Community 39 - "mail.php"
Cohesion: 0.12
Nodes (16): 1. Visión de Sistema, 2. Stack Tecnológico, 3. Estructura del Monorepo, 4.1. Diagrama Entidad-Relación, 4.2. Decisiones de Diseño de la BD, 4. Modelo de Datos Relacional (MySQL), 5.1. Modelo Offline-First y Sincronización, 5.2. Inventario basado en Recetas (CPP) (+8 more)

### Community 40 - "queue.php"
Cohesion: 0.18
Nodes (8): auth, navItems, route, router, theme, userInitials, userRole, useAuthStore

### Community 41 - "sanctum.php"
Cohesion: 0.15
Nodes (16): canSubmit, categories, currentCash, emit, errorMsg, fetchCategories(), form, handleClose() (+8 more)

### Community 42 - "services.php"
Cohesion: 0.19
Nodes (8): db, pruneOldSyncedSales(), syncPendingSales(), useCartStore, useCatalogStore, ../stores/orderQueue.js, useOrderQueueStore, handleConfirmCheckout()

### Community 43 - "session.php"
Cohesion: 0.09
Nodes (9): AuthController, Controller, ExpenseCategoryController, LoyaltyConfigController, OptionRecipeController, RecipeController, RolePermissionController, SaleController (+1 more)

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
Cohesion: 0.14
Nodes (11): authStore, emit, handleConfirmVoid(), isAdmin, isVoiding, sale, showVoidModal, turnoStore (+3 more)

### Community 49 - "vite.config.js"
Cohesion: 0.23
Nodes (22): apiFetch(), deleteExpense(), deleteRecurring(), fetchExpenses(), fetchPnL(), fetchRecurring(), saveExpense(), saveRecurring() (+14 more)

### Community 50 - "Plan: canal PedidosYa — arqueo separado, precios por canal y registro desde el POS"
Cohesion: 0.16
Nodes (9): AuditLogController, OrderQueueController, PedidosYaPricingController, EnsureUserHasRole, EnsureUserIsAdmin, EnsureUserIsSuperAdmin, Closure, Illuminate\Http\Request (+1 more)

### Community 52 - "ProductPauseModal.vue"
Cohesion: 0.23
Nodes (10): cancel(), confirm(), emit, infoMessage, nextDays, props, resetState(), selectedDate (+2 more)

### Community 53 - "BackupService"
Cohesion: 0.23
Nodes (3): SystemBackupController, BackupService, Symfony\Component\HttpFoundation\BinaryFileResponse

### Community 54 - "static"
Cohesion: 0.18
Nodes (3): RecurringExpenseController, RecurringExpenseRequest, RecurringExpense

### Community 55 - "Análisis del Funcionamiento del Sistema de Pedidos y Comandas (Ohana Acai V3)"
Cohesion: 0.07
Nodes (4): App\Traits\Auditable, CashMovement, ExpenseCategory, Illuminate\Database\Eloquent\Relations\HasMany

### Community 56 - "3. ⚠️ Reglas Obligatorias de Compatibilidad (Desarrollo desde Mac)"
Cohesion: 0.15
Nodes (13): apply(), emit, error, finalReason, hasOverride, isTariffPrice, newPrice, otherReason (+5 more)

### Community 57 - "App.vue"
Cohesion: 0.24
Nodes (8): auth, network, route, theme, useShell, network, ../stores/network.js, useNetworkStore

### Community 58 - "Guía para agentes — Ohana POS"
Cohesion: 0.15
Nodes (13): 1. Problema, 2. Diagnóstico: el doble precio ya existe, 3. Casos analizados, 4. Opciones evaluadas, 5. Fases, 6. Tests, 7. Fuera de alcance y limitaciones, 8. Forma de trabajo (+5 more)

### Community 60 - "AdminProductCard.vue"
Cohesion: 0.22
Nodes (7): assignedGroupsCount, emit, getImageUrl, pauseText, props, tagLabel, resolveImageUrl()

### Community 62 - "Diseño de Fase 1: Cimientos y Seguridad"
Cohesion: 0.14
Nodes (14): 1. Objetivo, 2. Lo que ya existe (no volver a crearlo), 3. Decisiones tomadas, 4.1 Arqueo (backend), 4.2 Pantallas de arqueo (frontend), 4.3 POS del cajero: selector de canal, 4.4 Validación de precios (backend), 4.5 Datos existentes (+6 more)

### Community 63 - "ProductOptionsModal.vue"
Cohesion: 0.28
Nodes (8): emit, handleSave(), isExcluded(), linkedGroups, localExcluded, props, selectedGroup, toggleExclusion()

### Community 64 - "CashSessionLinkTest"
Cohesion: 0.20
Nodes (4): OptionController, OptionRequest, OptionResource, Illuminate\Http\Resources\Json\AnonymousResourceCollection

### Community 66 - "require-dev"
Cohesion: 0.15
Nodes (13): 1. Objetivo, 2. Diagnóstico, 3. Opciones evaluadas, 4. Decisiones, 5.1 Base de datos, 5.2 Backend, 5.3 Frontend, 5. Cambios (+5 more)

### Community 67 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install --ignore-scripts, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 68 - "README.md"
Cohesion: 0.25
Nodes (9): emit, errorMessage, handleDownload(), isDownloading, onClose(), password, successInfo, API_URL (+1 more)

### Community 69 - "Closure"
Cohesion: 0.17
Nodes (16): getActiveOptionPrice(), displayPrice, emit, getImageUrl, handleAdd(), isAdding, props, usesLocalPrice (+8 more)

### Community 70 - "KdsController"
Cohesion: 0.18
Nodes (10): 1. Arquitectura General (Offline-First), 2.1. Gestión del Carrito (`cart.js`), 2.2. Proceso de Checkout y Generación de la Comanda Local, 2. Flujo de Toma de Pedidos (Frontend), 3. Lógica de Sincronización (Red), 4.1. Recepción y Transaccionalidad, 4.2. Registro y Consolidación, 4. Procesamiento Final (Backend - Laravel) (+2 more)

### Community 71 - "SaleRepository"
Cohesion: 0.18
Nodes (10): 1. Ficha Técnica de la Portátil de Producción (Windows), 2. Flujo de Actualización Automática en la Portátil, 3. ⚠️ Reglas Obligatorias de Compatibilidad (Desarrollo desde Mac), 4. Checklist Rápido antes de hacer `git push` desde la Mac, 🗄️ A. Base de Datos y Migraciones, 📁 B. Manejo de Rutas de Archivos (Path Handling), 🌐 C. Rutas API y Autenticación (Sanctum), 📦 D. Dependencias y Paquetes (Composer y NPM) (+2 more)

### Community 74 - "config"
Cohesion: 0.20
Nodes (10): Bitácora del proyecto, Correcciones de datos (requieren confirmación explícita, no aplicar sin ella), Decisiones del usuario, Decisiones tomadas, Estado actual (2026-10-07), Fase 5 (diseñada, aparcada) — problemas vigentes, Fase 6 (sin empezar), Pendientes (+2 more)

### Community 75 - "ProductCard.vue"
Cohesion: 0.24
Nodes (8): emit, errorMessage, handleRestore(), isRestoring, onClose(), password, selectedFile, successMessage

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
Cohesion: 0.20
Nodes (9): Análisis de Módulos y Plan de Desarrollo - Ohana Acai V3, 📦 Detalle Técnico de los Módulos Identificados, Fase 1: Cimientos y Seguridad, Fase 2: Catálogo Base (Lo que se vende), Fase 3: Gestión de Materia Prima (Lo que se consume), Fase 4: El Puente (Recetas), Fase 5: Operación y Cobro, Fase 6: Expansión Comercial (+1 more)

### Community 80 - "SalePricingService"
Cohesion: 0.33
Nodes (3): FinanceReportController, FinanceReportService, Illuminate\Support\Collection

### Community 81 - "psr-4"
Cohesion: 0.20
Nodes (10): 🚀 Arquitectura y Tecnologías, Backend (RESTful API), Configuración del Backend (Laravel), Configuración del Frontend (Vue.js), 📦 Estructura del Proyecto, Frontend (SPA / PWA), 🛠 Instalación y Configuración Local, Ohana Acai V3 - Sistema POS y Gestión (+2 more)

### Community 83 - "AssignOptionGroupsModal.vue"
Cohesion: 0.50
Nodes (4): emit, handleConfirm(), localSelected, props

### Community 84 - "InventoryMovementRequest"
Cohesion: 0.25
Nodes (8): 1. Identificación y Ubicación en el Código, 2. Jerarquía y Distribución Espacial de Elementos, 3.1. Barra Superior Global (`Menu.vue`), 3.2. Sidebar Izquierdo (`Menu.vue` líneas 530–555), 3.3. Panel Central de Detalle (`OptionGroupDetail.vue` líneas 250–345), 3.4. Modal Superpuesto en Primer Plano (`OptionGroupDetail.vue` líneas 358–406), 3. Desglose Detallado por Componente, Análisis de Interfaz: Módulo de Gestión de Opcionales y Modal de Edición

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

### Community 90 - "vigilante-actualizaciones.ps1"
Cohesion: 0.22
Nodes (8): 1. Flujo de Login (Híbrido: Online / Offline), 2. Flujo de Matriz de Permisos (RBAC), `AdminUsers.vue`, Diseño de Fase 1: Cimientos y Seguridad, 🗄️ Esquema de Base de Datos (ERD), 🔄 Flujos de Sistema (Sequence Diagrams), 🎨 Interfaz de Usuario (UI), `Login.vue`

### Community 92 - "0001_01_01_000000_create_users_table.php"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 93 - "0001_01_01_000001_create_cache_table.php"
Cohesion: 0.25
Nodes (7): About Laravel, Agentic Development, Code of Conduct, Contributing, Learning Laravel, License, Security Vulnerabilities

### Community 96 - "2026_07_16_000002_create_categories_table.php"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 97 - "2026_07_16_000003_create_products_table.php"
Cohesion: 0.29
Nodes (7): Al empezar una sesión, Datos del sistema que no son obvios, Guía para agentes — Ohana POS, Mapa rápido, Reglas de trabajo, Trampas conocidas, Verificación obligatoria

### Community 98 - "2026_07_16_000004_create_ingredients_table.php"
Cohesion: 0.33
Nodes (6): 6.1 Flujo para el dueño (cada semana, ~2 minutos), 6.2 Lectura del CSV (reglas), 6.3 Conciliación automática, 6.4 Datos (backend), 6.5 Vínculo con el POS (fase 3), 6. Importación semanal de PedidosYa

### Community 99 - "2026_07_16_000005_create_recipes_table.php"
Cohesion: 0.33
Nodes (6): Agregado, Cambiado, Changelog, Corregido, Seguridad, [Sin publicar] — rama `mejoras/analisis-integral`

### Community 100 - "2026_07_16_000006_create_sales_table.php"
Cohesion: 0.33
Nodes (5): 🍏 Guía de Conexión desde el Mac al Servidor Windows (Ohana POS), Notas Técnicas, Opción 1: Estás en tu casa (Conexión Directa por LAN) 🏠, Opción 2: Estás fuera de casa o en el negocio (Vía Cloudflare Tunnel) 🌍, Resumen Rápido

### Community 101 - "2026_07_16_000007_create_sale_payments_table.php"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 102 - "2026_07_16_000008_create_sale_items_table.php"
Cohesion: 0.40
Nodes (5): require, laravel/framework, laravel/sanctum, laravel/tinker, php

### Community 104 - "2026_07_16_000010_create_loyalty_config_table.php"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 165 - "cart.js"
Cohesion: 0.40
Nodes (3): emit, parsedChanges, props

### Community 166 - "theme.js"
Cohesion: 0.70
Nodes (3): useThemeStore, applyHardwareOptimizations(), detectHardwareProfile()

### Community 168 - "CashSessionResolver.php"
Cohesion: 0.50
Nodes (4): 4.1 Qué contiene cada archivo, 4.2 La semana en números, 4.3 Hallazgos, 4. Archivos semanales de PedidosYa — análisis (semana 14/09 al 20/09/2026)

## Knowledge Gaps
- **677 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+672 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **20 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `apiFetch()` connect `vite.config.js` to `Illuminate\Database\Eloquent\Factories\HasFactory`, `Pos.vue`, `README.md`, `filesystems.php`, `Product`, `package.json`, `queue.php`, `sanctum.php`, `services.php`, `Illuminate\Foundation\Http\FormRequest`, `AppServiceProvider`, `vite.config.js`, `0001_01_01_000002_create_jobs_table.php`, `2026_07_16_000006_create_sales_table.php`?**
  _High betweenness centrality (0.033) - this node is a cross-community bridge._
- **Why does `Product` connect `UserFactory` to `Category`, `composer.json`, `database.php`, `ProductRepository`, `2026_07_16_000012_create_delivery_orders_table.php`, `Plan: canal PedidosYa — arqueo separado, precios por canal y registro desde el POS`, `IngredientRepository`, `require`, `2026_07_16_000004_create_ingredients_table.php`, `2026_07_16_000005_create_recipes_table.php`, `Análisis del Funcionamiento del Sistema de Pedidos y Comandas (Ohana Acai V3)`, `2026_07_16_000009_create_inventory_movements_table.php`, `🛣️ Ruta de Desarrollo Sugerida (Por Dependencias)`, `2026_07_16_000012_create_delivery_orders_table.php`, `0001_01_01_000002_create_jobs_table.php`, `2026_07_16_000001_create_customers_table.php`?**
  _High betweenness centrality (0.022) - this node is a cross-community bridge._
- **Why does `User` connect `Category` to `composer.json`, `database.php`, `SalePricingTest`, `ProductRepository`, `session.php`, `UserFactory`, `require`, `2026_07_16_000004_create_ingredients_table.php`, `2026_07_16_000005_create_recipes_table.php`, `Análisis del Funcionamiento del Sistema de Pedidos y Comandas (Ohana Acai V3)`, `2026_07_16_000007_create_sale_payments_table.php`, `2026_07_16_000009_create_inventory_movements_table.php`, `🛣️ Ruta de Desarrollo Sugerida (Por Dependencias)`, `2026_07_16_000012_create_delivery_orders_table.php`, `0001_01_01_000002_create_jobs_table.php`, `2026_07_16_000001_create_customers_table.php`?**
  _High betweenness centrality (0.016) - this node is a cross-community bridge._
- **Are the 16 inferred relationships involving `User` (e.g. with `.login()` and `.run()`) actually correct?**
  _`User` has 16 INFERRED edges - model-reasoned connections that need verification._
- **Are the 14 inferred relationships involving `Product` (e.g. with `.handle()` and `.update()`) actually correct?**
  _`Product` has 14 INFERRED edges - model-reasoned connections that need verification._
- **Are the 19 inferred relationships involving `Sale` (e.g. with `.calculateSessionStats()` and `.store()`) actually correct?**
  _`Sale` has 19 INFERRED edges - model-reasoned connections that need verification._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _677 weakly-connected nodes found - possible documentation gaps or missing edges._