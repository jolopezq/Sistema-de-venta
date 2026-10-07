# Graph Report - /Users/jolopez/Sistema de Ventas  (2026-10-04)

## Corpus Check
- Large corpus: 288 files · ~623,296 words. Semantic extraction will be expensive (many Claude tokens). Consider running on a subfolder.

## Summary
- 1706 nodes · 2782 edges · 165 communities (147 shown, 18 thin omitted)
- Extraction: 96% EXTRACTED · 4% INFERRED · 0% AMBIGUOUS · INFERRED: 116 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- components: AdminSaleEditor.vue, addPayment()
- tests/Feature: .toArray(), .parent()
- components: AuditLogDetailModal.vue, emit
- app/Models: AuditLog.php, CashMovement.php
- views: AdminUsers.vue, activeTab
- views: Menu.vue, activeCategory
- views: RetroactiveSales.vue, activeTab
- views: InventoryList.vue, alertAction()
- components: CashCloseWizard.vue, authStore
- app/Http/Requests: AdminSaleRequest.php, Admi
- frontend: dexie, package.json
- components: ModifierModal.vue, catalog
- tests/Feature/Catalog: App\Traits\Auditable, 
- app/Http/Controllers: AuthController.php, Aut
- views: CartItem.vue, emit
- views: Delivery.vue, allergenMap
- app/Http/Controllers: CashRegisterSessionCont
- components/Menu: OptionGroupDetail.vue, delet
- views: Turno.vue, applyCustomDateRange()
- components/Menu: ProductForm.vue, activeCateg
- components: CheckoutModal.vue, addPayment()
- app/Http/Controllers: UserController.php, Req
- app/Http/Controllers: CustomerController.php,
- docs: PLAN_PROYECTO_ANDROID_KDS.md, 1. Autent
- app/Http/Controllers: IngredientCategoryContr
- components: KdsOrderDetailModal.vue, allergen
- app/Models: KdsController.php, KdsController
- app/Http/Controllers: ProductController.php, 
- app/Http/Controllers: AuditLogController.php,
- app/Services: .__construct(), .handle()
- backend: package.json, devDependencies
- views: main.js, app
- app/Http/Controllers: IngredientController.ph
- app/Http/Controllers: OptionController.php, O
- app/Http/Controllers: OptionGroupController.p
- app/Models: Category.php, .scopeOrdered()
- docs: architecture.md, 1. Visión de Sistema
- components/Menu: OptionGroupForm.vue, confirm
- app/Services: InventoryMovementController, ._
- app/Http/Controllers: CategoryController.php,
- components: AppShell.vue, auth
- components: CashExpenseModal.vue, canSubmit
- stores: database.js, db
- app/Models: .movements(), .children()
- backend: composer.json, autoload-dev
- backend: scripts, dev
- components: KitchenTicketModal.vue, allergenT
- components/Menu: AssignProductsModal.vue, emi
- components: SaleDetailDrawer.vue, authStore
- views: apiFetch(), alertAction()
- app/Repositories: Ingredient, .casts()
- app/Repositories: AppServiceProvider.php, App
- components/Menu: ProductPauseModal.vue, cance
- app/Http/Controllers: SystemBackupController.
- database/factories: .active(), Auditable.php
- docs: ANALISIS_SISTEMA_PEDIDOS.md, 1. Arquite
- docs: CONFIGURACION_ENTORNO_Y_COMPATIBILIDAD.
- src: App.vue, auth
- README.md, 🚀 Arquitectura y Tecnologías
- docs: implementation_plan.md, Análisis de Mód
- components/Menu: AdminProductCard.vue, assign
- tests/Feature: AdminSaleManagementTest, .test
- docs: phase1_design.md, 1. Flujo de Login (Hí
- components/Menu: ProductOptionsModal.vue, emi
- app/Http/Controllers: CatalogController, .__c
- app/Jobs: ProcessOfflineSaleJob.php, ProcessO
- backend: require-dev, fakerphp/faker
- backend: post-root-package-install, setup
- backend: README.md, About Laravel
- views: getLocalDateStr(), handleExpenseSaved(
- app/Http/Controllers: LoyaltyConfigController
- app/Http/Controllers: OptionRecipeController.
- tests/Feature: Customer.php, KdsApiTest.php
- app/Repositories: ProductRepository, .create(
- backend: pestphp/pest-plugin, php-http/discov
- components: ProductCard.vue, displayPrice
- components: ReceiptModal.vue, change
- components: CashSessionReport.vue, formatDate
- components: PinModal.vue, emit
- GUIA-CONEXION-MAC.md, 🍏 Guía de Conexión desd
- app/Http/Resources: CategoryResource.php, Cus
- backend: autoload, psr-4
- backend: require, laravel/framework
- components/Menu: AssignOptionGroupsModal.vue,
- app/Console/Commands: ReactivateProducts.php,
- app/Repositories: .paginated(), UserRepositor
- backend: post-create-project-cmd, @php artisa
- tests/Unit: ExampleTest.php, ExampleTest
- frontend: capture_dark_mode.cjs, { chromium }
- frontend: capture_dark_pos.cjs, { chromium }
- backend: extra, laravel
- backend: test_api.sh, test_api.sh script
- docs: task.md, Tareas - Fase 1: Cimientos y S
- frontend: README.md, Vue 3 + Vite
- components: HelloWorld.vue, count
- views: handleEditFromDrawer(), openEditSale()
- iniciar-pos.sh, iniciar-pos.sh script

## God Nodes (most connected - your core abstractions)
1. `Sale` - 49 edges
2. `User` - 47 edges
3. `apiFetch()` - 47 edges
4. `Product` - 43 edges
5. `Category` - 33 edges
6. `Ingredient` - 32 edges
7. `TestCase` - 26 edges
8. `CashRegisterSession` - 24 edges
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

## Communities (165 total, 18 thin omitted)

### Community 0 - "components: AdminSaleEditor.vue, addPayment()"
Cohesion: 0.06
Nodes (42): addProductToSale(), autoSyncPayments(), calculatedSubtotal, calculatedTotal, catalogStore, emit, errorMessage, filteredAvailableProducts (+34 more)

### Community 1 - "tests/Feature: .toArray(), .parent()"
Cohesion: 0.07
Nodes (13): User, CategoryTest, OptionGroupTest, ExampleTest, OrderQueueTest, SaleSyncTest, SystemBackupTest, UserManagementTest (+5 more)

### Community 2 - "components: AuditLogDetailModal.vue, emit"
Cohesion: 0.05
Nodes (34): emit, parsedChanges, props, emit, errorMessage, handleDownload(), isDownloading, onClose() (+26 more)

### Community 3 - "app/Models: AuditLog.php, CashMovement.php"
Cohesion: 0.09
Nodes (9): CashMovement, DeliveryOrder, PlatformCatalogMapping, ProductVariant, SaleItem, SaleItemOption, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\Model (+1 more)

### Community 4 - "views: AdminUsers.vue, activeTab"
Cohesion: 0.06
Nodes (36): activeTab, alertAction(), alertMessage, authStore, confirmAction(), confirmCallback, confirmMessage, decidedCount (+28 more)

### Community 5 - "views: Menu.vue, activeCategory"
Cohesion: 0.05
Nodes (36): activeCategory, activeCategoryProducts, activeOptionGroup, activeProductForModal, activeProductForPause, activeTab, alertMessage, categories (+28 more)

### Community 6 - "views: RetroactiveSales.vue, activeTab"
Cohesion: 0.05
Nodes (37): activeTab, authStore, cardSales, cashExpenses, cashSales, closedReport, currentSession, currentStats (+29 more)

### Community 7 - "views: InventoryList.vue, alertAction()"
Cohesion: 0.07
Nodes (31): alertAction(), alertMessage, categories, categoryForm, confirmAction(), confirmCallback, confirmMessage, deleteCategory() (+23 more)

### Community 8 - "components: CashCloseWizard.vue, authStore"
Cohesion: 0.07
Nodes (29): authStore, billCounts, calculatedTotal, canProceedToClose, cashLeftForNext, cashSales, cashWithdrawn, changeQty() (+21 more)

### Community 9 - "app/Http/Requests: AdminSaleRequest.php, Admi"
Cohesion: 0.08
Nodes (7): AdminSaleRequest, InventoryMovementRequest, LoginRequest, RecipeRequest, RolePermissionRequest, SyncSalesRequest, Illuminate\Foundation\Http\FormRequest

### Community 10 - "frontend: dexie, package.json"
Cohesion: 0.06
Nodes (30): dexie, dependencies, dexie, pinia, vue, vue-router, devDependencies, playwright (+22 more)

### Community 11 - "components: ModifierModal.vue, catalog"
Cohesion: 0.09
Nodes (26): catalog, confirmButtonText, emit, errorMessages, extrasPrice, filteredGroups, getGroupSelectedCount(), getMinRequired() (+18 more)

### Community 12 - "tests/Feature/Catalog: App\Traits\Auditable, "
Cohesion: 0.14
Nodes (6): App\Traits\Auditable, Category, OptionGroup, Product, Recipe, ProductTest

### Community 13 - "app/Http/Controllers: AuthController.php, Aut"
Cohesion: 0.12
Nodes (7): AuthController, Controller, RecipeController, RolePermissionController, SaleController, RolePermission, Illuminate\Http\JsonResponse

### Community 14 - "views: CartItem.vue, emit"
Cohesion: 0.07
Nodes (14): emit, activeCategoryId, cart, catalog, completedSale, editingCartItem, filteredProducts, ingredientStockMap (+6 more)

### Community 15 - "views: Delivery.vue, allergenMap"
Cohesion: 0.10
Nodes (19): allergenMap, auth, columns, expandedOrders, formatElapsed(), getCustomerName(), getDisplayOrderNumber(), getElapsedMinutes() (+11 more)

### Community 16 - "app/Http/Controllers: CashRegisterSessionCont"
Cohesion: 0.15
Nodes (4): CashRegisterSessionController, CashRegisterSession, SalePayment, CashRegisterSessionTest

### Community 17 - "components/Menu: OptionGroupDetail.vue, delet"
Cohesion: 0.09
Nodes (18): deleteOption(), draggedIndex, dragOverIndex, editingOption, emit, handleAssignSave(), isSavingOrder, isSubmittingOption (+10 more)

### Community 18 - "views: Turno.vue, applyCustomDateRange()"
Cohesion: 0.08
Nodes (14): authStore, customFrom, customTo, editingSaleForAdmin, isAdmin, isCashExpenseModalOpen, isOpening, isRetroactiveModalOpen (+6 more)

### Community 19 - "components/Menu: ProductForm.vue, activeCateg"
Cohesion: 0.09
Nodes (17): activeCategoryName, activeTagName, categoryDropdownRef, compressImage(), deleteProduct(), emit, fileInput, handleImageUpload() (+9 more)

### Community 20 - "components: CheckoutModal.vue, addPayment()"
Cohesion: 0.09
Nodes (18): cashNeededAmount, cashReceived, change, currentMethod, customerSearch, emit, handleConfirm(), hasMixedCapability (+10 more)

### Community 21 - "app/Http/Controllers: UserController.php, Req"
Cohesion: 0.12
Nodes (5): Request, UserController, UserRequest, UserResource, UserRepository

### Community 22 - "app/Http/Controllers: CustomerController.php,"
Cohesion: 0.15
Nodes (5): CustomerController, CustomerRequest, CustomerResource, Customer, CustomerRepository

### Community 23 - "docs: PLAN_PROYECTO_ANDROID_KDS.md, 1. Autent"
Cohesion: 0.09
Nodes (21): 1. Autenticación de Cocina, 1. 🎯 Visión General y Arquitectura del Ecosistema, 2. 🌐 Direcciones de la API del Backend (Contrato de Integración), 2. Obtener Lista de Comandas Activas (Cola KDS), 3. Avanzar Estado de la Comanda, 3. 🎨 Especificación UX: Tarjeta KDS en Android (Sin Ventanas Emergentes), 4. 🛠️ Implementación en Código Jetpack Compose (Kotlin), 5. 📋 Plan de Acción de 5 Fases para la App Android (+13 more)

### Community 24 - "app/Http/Controllers: IngredientCategoryContr"
Cohesion: 0.15
Nodes (4): IngredientCategoryController, IngredientCategoryRequest, IngredientCategory, IngredientCategoryRepository

### Community 25 - "components: KdsOrderDetailModal.vue, allergen"
Cohesion: 0.11
Nodes (12): allergenMap, completedItems, emit, formatElapsed(), getDisplayNumber(), getElapsedMinutes(), getFullCode(), getOrderDailySequence() (+4 more)

### Community 26 - "app/Models: KdsController.php, KdsController"
Cohesion: 0.16
Nodes (5): KdsController, Sale, Illuminate\Database\Eloquent\Concerns\HasUuids, Illuminate\Database\Eloquent\Relations\HasOne, Symfony\Component\HttpFoundation\StreamedResponse

### Community 27 - "app/Http/Controllers: ProductController.php, "
Cohesion: 0.15
Nodes (5): Request, ProductController, ProductRequest, ProductResource, Illuminate\Http\Response

### Community 28 - "app/Http/Controllers: AuditLogController.php,"
Cohesion: 0.21
Nodes (7): AuditLogController, OrderQueueController, EnsureUserIsAdmin, EnsureUserIsSuperAdmin, Closure, Illuminate\Http\Request, Symfony\Component\HttpFoundation\Response

### Community 29 - "app/Services: .__construct(), .handle()"
Cohesion: 0.19
Nodes (4): AuditLog, SaleRepository, AuditLogger, SaleSyncService

### Community 30 - "backend: package.json, devDependencies"
Cohesion: 0.11
Nodes (17): devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, vite, private (+9 more)

### Community 31 - "views: main.js, app"
Cohesion: 0.12
Nodes (14): app, updateSW, router, routes, useCatalogStore, auth, catalog, email (+6 more)

### Community 32 - "app/Http/Controllers: IngredientController.ph"
Cohesion: 0.17
Nodes (4): IngredientController, IngredientRequest, IngredientResource, Illuminate\Http\Resources\Json\AnonymousResourceCollection

### Community 33 - "app/Http/Controllers: OptionController.php, O"
Cohesion: 0.20
Nodes (4): OptionController, OptionRequest, OptionResource, Option

### Community 34 - "app/Http/Controllers: OptionGroupController.p"
Cohesion: 0.18
Nodes (4): OptionGroupController, JsonResponse, OptionGroupRequest, OptionGroupResource

### Community 35 - "app/Models: Category.php, .scopeOrdered()"
Cohesion: 0.21
Nodes (6): DatabaseSeeder, Illuminate\Database\Eloquent\Builder, Illuminate\Database\Eloquent\SoftDeletes, Illuminate\Database\Seeder, Illuminate\Notifications\Notifiable, Laravel\Sanctum\HasApiTokens

### Community 36 - "docs: architecture.md, 1. Visión de Sistema"
Cohesion: 0.12
Nodes (16): 1. Visión de Sistema, 2. Stack Tecnológico, 3. Estructura del Monorepo, 4.1. Diagrama Entidad-Relación, 4.2. Decisiones de Diseño de la BD, 4. Modelo de Datos Relacional (MySQL), 5.1. Modelo Offline-First y Sincronización, 5.2. Inventario basado en Recetas (CPP) (+8 more)

### Community 37 - "components/Menu: OptionGroupForm.vue, confirm"
Cohesion: 0.14
Nodes (12): deleteGroup(), editingOptionIndex, emit, handleAssignSave(), linkedProducts, localGroup, newOptName, newOptPrice (+4 more)

### Community 38 - "app/Services: InventoryMovementController, ._"
Cohesion: 0.17
Nodes (4): InventoryMovementController, InventoryMovement, InventoryService, Collection

### Community 39 - "app/Http/Controllers: CategoryController.php,"
Cohesion: 0.19
Nodes (4): CategoryController, JsonResponse, CategoryRequest, CategoryResource

### Community 40 - "components: AppShell.vue, auth"
Cohesion: 0.17
Nodes (10): auth, navItems, route, router, theme, userInitials, userRole, useThemeStore (+2 more)

### Community 41 - "components: CashExpenseModal.vue, canSubmit"
Cohesion: 0.17
Nodes (14): canSubmit, currentCash, emit, errorMsg, form, handleClose(), handleSubmit(), isOverdrawn (+6 more)

### Community 42 - "stores: database.js, db"
Cohesion: 0.20
Nodes (6): db, pruneOldSyncedSales(), syncPendingSales(), useCartStore, ../stores/orderQueue.js, useOrderQueueStore

### Community 44 - "backend: composer.json, autoload-dev"
Cohesion: 0.14
Nodes (13): autoload-dev, psr-4, description, keywords, license, minimum-stability, name, prefer-stable (+5 more)

### Community 45 - "backend: scripts, dev"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 46 - "components: KitchenTicketModal.vue, allergenT"
Cohesion: 0.15
Nodes (8): allergenTextMap, attrs, auth, emit, hasNextAction, props, ticketText, useAuthStore

### Community 47 - "components/Menu: AssignProductsModal.vue, emi"
Cohesion: 0.20
Nodes (10): emit, expandedCategories, getProductsByCategory(), handleSave(), isCategoryFullySelected(), isCategoryIndeterminate(), props, searchQuery (+2 more)

### Community 48 - "components: SaleDetailDrawer.vue, authStore"
Cohesion: 0.15
Nodes (11): authStore, emit, handleConfirmVoid(), isAdmin, isVoiding, sale, showVoidModal, turnoStore (+3 more)

### Community 49 - "views: apiFetch(), alertAction()"
Cohesion: 0.37
Nodes (14): apiFetch(), alertAction(), confirmAction(), confirmPauseProduct(), deleteCategory(), deleteOptionGroup(), deleteProduct(), fetchData() (+6 more)

### Community 51 - "app/Repositories: AppServiceProvider.php, App"
Cohesion: 0.20
Nodes (3): AppServiceProvider, Illuminate\Database\Eloquent\Collection, Illuminate\Support\ServiceProvider

### Community 52 - "components/Menu: ProductPauseModal.vue, cance"
Cohesion: 0.23
Nodes (10): cancel(), confirm(), emit, infoMessage, nextDays, props, resetState(), selectedDate (+2 more)

### Community 53 - "app/Http/Controllers: SystemBackupController."
Cohesion: 0.25
Nodes (3): SystemBackupController, BackupService, Symfony\Component\HttpFoundation\BinaryFileResponse

### Community 54 - "database/factories: .active(), Auditable.php"
Cohesion: 0.24
Nodes (6): bootAuditable(), logAction(), UserFactory, Illuminate\Database\Eloquent\Factories\Factory, self, static

### Community 55 - "docs: ANALISIS_SISTEMA_PEDIDOS.md, 1. Arquite"
Cohesion: 0.18
Nodes (10): 1. Arquitectura General (Offline-First), 2.1. Gestión del Carrito (`cart.js`), 2.2. Proceso de Checkout y Generación de la Comanda Local, 2. Flujo de Toma de Pedidos (Frontend), 3. Lógica de Sincronización (Red), 4.1. Recepción y Transaccionalidad, 4.2. Registro y Consolidación, 4. Procesamiento Final (Backend - Laravel) (+2 more)

### Community 56 - "docs: CONFIGURACION_ENTORNO_Y_COMPATIBILIDAD."
Cohesion: 0.18
Nodes (10): 1. Ficha Técnica de la Portátil de Producción (Windows), 2. Flujo de Actualización Automática en la Portátil, 3. ⚠️ Reglas Obligatorias de Compatibilidad (Desarrollo desde Mac), 4. Checklist Rápido antes de hacer `git push` desde la Mac, 🗄️ A. Base de Datos y Migraciones, 📁 B. Manejo de Rutas de Archivos (Path Handling), 🌐 C. Rutas API y Autenticación (Sanctum), 📦 D. Dependencias y Paquetes (Composer y NPM) (+2 more)

### Community 57 - "src: App.vue, auth"
Cohesion: 0.22
Nodes (9): auth, network, route, theme, useShell, network, ../stores/network.js, useNetworkStore (+1 more)

### Community 58 - "README.md, 🚀 Arquitectura y Tecnologías"
Cohesion: 0.18
Nodes (10): 🚀 Arquitectura y Tecnologías, Backend (RESTful API), Configuración del Backend (Laravel), Configuración del Frontend (Vue.js), 📦 Estructura del Proyecto, Frontend (SPA / PWA), 🛠 Instalación y Configuración Local, Ohana Acai V3 - Sistema POS y Gestión (+2 more)

### Community 59 - "docs: implementation_plan.md, Análisis de Mód"
Cohesion: 0.20
Nodes (9): Análisis de Módulos y Plan de Desarrollo - Ohana Acai V3, 📦 Detalle Técnico de los Módulos Identificados, Fase 1: Cimientos y Seguridad, Fase 2: Catálogo Base (Lo que se vende), Fase 3: Gestión de Materia Prima (Lo que se consume), Fase 4: El Puente (Recetas), Fase 5: Operación y Cobro, Fase 6: Expansión Comercial (+1 more)

### Community 60 - "components/Menu: AdminProductCard.vue, assign"
Cohesion: 0.22
Nodes (7): assignedGroupsCount, emit, getImageUrl, pauseText, props, tagLabel, resolveImageUrl()

### Community 62 - "docs: phase1_design.md, 1. Flujo de Login (Hí"
Cohesion: 0.22
Nodes (8): 1. Flujo de Login (Híbrido: Online / Offline), 2. Flujo de Matriz de Permisos (RBAC), `AdminUsers.vue`, Diseño de Fase 1: Cimientos y Seguridad, 🗄️ Esquema de Base de Datos (ERD), 🔄 Flujos de Sistema (Sequence Diagrams), 🎨 Interfaz de Usuario (UI), `Login.vue`

### Community 63 - "components/Menu: ProductOptionsModal.vue, emi"
Cohesion: 0.28
Nodes (8): emit, handleSave(), isExcluded(), linkedGroups, localExcluded, props, selectedGroup, toggleExclusion()

### Community 65 - "app/Jobs: ProcessOfflineSaleJob.php, ProcessO"
Cohesion: 0.43
Nodes (6): ProcessOfflineSaleJob, Illuminate\Bus\Queueable, Illuminate\Contracts\Queue\ShouldQueue, Illuminate\Foundation\Bus\Dispatchable, Illuminate\Queue\InteractsWithQueue, Illuminate\Queue\SerializesModels

### Community 66 - "backend: require-dev, fakerphp/faker"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 67 - "backend: post-root-package-install, setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install --ignore-scripts, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 68 - "backend: README.md, About Laravel"
Cohesion: 0.25
Nodes (7): About Laravel, Agentic Development, Code of Conduct, Contributing, Learning Laravel, License, Security Vulnerabilities

### Community 69 - "views: getLocalDateStr(), handleExpenseSaved("
Cohesion: 0.25
Nodes (8): getLocalDateStr(), handleExpenseSaved(), handleOpenSession(), handleSaleEdited(), handleSaleVoided(), handleSavedSale(), handleSessionClosed(), loadDayData()

### Community 74 - "backend: pestphp/pest-plugin, php-http/discov"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 75 - "components: ProductCard.vue, displayPrice"
Cohesion: 0.33
Nodes (6): displayPrice, emit, getImageUrl, handleAdd(), isAdding, props

### Community 76 - "components: ReceiptModal.vue, change"
Cohesion: 0.29
Nodes (5): change, displayOrderNum, emit, orderFullCode, props

### Community 77 - "components: CashSessionReport.vue, formatDate"
Cohesion: 0.33
Nodes (4): props, reportData, session, summary

### Community 78 - "components: PinModal.vue, emit"
Cohesion: 0.47
Nodes (5): emit, handleClose(), handleSubmit(), pin, props

### Community 79 - "GUIA-CONEXION-MAC.md, 🍏 Guía de Conexión desd"
Cohesion: 0.33
Nodes (5): 🍏 Guía de Conexión desde el Mac al Servidor Windows (Ohana POS), Notas Técnicas, Opción 1: Estás en tu casa (Conexión Directa por LAN) 🏠, Opción 2: Estás fuera de casa o en el negocio (Vía Cloudflare Tunnel) 🌍, Resumen Rápido

### Community 81 - "backend: autoload, psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 82 - "backend: require, laravel/framework"
Cohesion: 0.40
Nodes (5): require, laravel/framework, laravel/sanctum, laravel/tinker, php

### Community 83 - "components/Menu: AssignOptionGroupsModal.vue,"
Cohesion: 0.50
Nodes (4): emit, handleConfirm(), localSelected, props

### Community 86 - "backend: post-create-project-cmd, @php artisa"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 88 - "frontend: capture_dark_mode.cjs, { chromium }"
Cohesion: 0.50
Nodes (3): { chromium }, fs, path

### Community 89 - "frontend: capture_dark_pos.cjs, { chromium }"
Cohesion: 0.50
Nodes (3): { chromium }, fs, path

### Community 91 - "backend: extra, laravel"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps
- **526 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+521 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **18 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `apiFetch()` connect `views: apiFetch(), alertAction()` to `components: AdminSaleEditor.vue, addPayment()`, `components: AuditLogDetailModal.vue, emit`, `views: AdminUsers.vue, activeTab`, `components/Menu: OptionGroupForm.vue, confirm`, `views: Menu.vue, activeCategory`, `views: InventoryList.vue, alertAction()`, `stores: database.js, db`, `components: KitchenTicketModal.vue, allergenT`, `components/Menu: OptionGroupDetail.vue, delet`?**
  _High betweenness centrality (0.027) - this node is a cross-community bridge._
- **Why does `Product` connect `tests/Feature/Catalog: App\Traits\Auditable, ` to `tests/Feature: .toArray(), .parent()`, `app/Models: AuditLog.php, CashMovement.php`, `app/Models: Category.php, .scopeOrdered()`, `app/Services: InventoryMovementController, ._`, `tests/Feature: Customer.php, KdsApiTest.php`, `app/Repositories: ProductRepository, .create(`, `app/Models: .movements(), .children()`, `app/Repositories: AppServiceProvider.php, App`, `app/Console/Commands: ReactivateProducts.php,`, `app/Repositories: .paginated(), UserRepositor`, `tests/Feature: AdminSaleManagementTest, .test`?**
  _High betweenness centrality (0.014) - this node is a cross-community bridge._
- **Why does `Ingredient` connect `app/Repositories: Ingredient, .casts()` to `app/Http/Controllers: CatalogController, .__c`, `tests/Feature: .toArray(), .parent()`, `app/Models: AuditLog.php, CashMovement.php`, `app/Models: Category.php, .scopeOrdered()`, `app/Services: InventoryMovementController, ._`, `app/Http/Controllers: LoyaltyConfigController`, `app/Models: .movements(), .children()`, `tests/Feature/Catalog: App\Traits\Auditable, `, `app/Http/Controllers: AuthController.php, Aut`, `tests/Feature: AdminSaleManagementTest, .test`, `app/Services: .__construct(), .handle()`?**
  _High betweenness centrality (0.012) - this node is a cross-community bridge._
- **Are the 9 inferred relationships involving `Sale` (e.g. with `.calculateSessionStats()` and `.test_cannot_edit_voided_sale()`) actually correct?**
  _`Sale` has 9 INFERRED edges - model-reasoned connections that need verification._
- **Are the 14 inferred relationships involving `User` (e.g. with `.login()` and `.run()`) actually correct?**
  _`User` has 14 INFERRED edges - model-reasoned connections that need verification._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _526 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `components: AdminSaleEditor.vue, addPayment()` be split into smaller, more focused modules?**
  _Cohesion score 0.06431372549019608 - nodes in this community are weakly interconnected._