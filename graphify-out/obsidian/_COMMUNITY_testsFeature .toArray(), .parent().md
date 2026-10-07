---
type: community
cohesion: 0.07
members: 50
---

# tests/Feature: .toArray(), .parent()

**Cohesion:** 0.07 - loosely connected
**Members:** 50 nodes

## Members
- [[.casts()_13]] - code - backend/app/Models/User.php
- [[.isAdmin()]] - code - backend/app/Models/User.php
- [[.isCashier()]] - code - backend/app/Models/User.php
- [[.isKitchen()]] - code - backend/app/Models/User.php
- [[.isSuperAdmin()]] - code - backend/app/Models/User.php
- [[.parent()]] - code - backend/app/Models/Category.php
- [[.setUp()_1]] - code - backend/tests/Feature/CashRegisterSessionTest.php
- [[.setUp()_2]] - code - backend/tests/Feature/Catalog/CategoryTest.php
- [[.setUp()_3]] - code - backend/tests/Feature/Catalog/OptionGroupTest.php
- [[.setUp()_4]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[.setUp()_6]] - code - backend/tests/Feature/OrderQueueTest.php
- [[.setUp()_7]] - code - backend/tests/Unit/SaleTransactionTest.php
- [[.test_admin_can_manage_users()]] - code - backend/tests/Feature/UserManagementTest.php
- [[.test_can_advance_order_status_in_queue()]] - code - backend/tests/Feature/OrderQueueTest.php
- [[.test_can_create_option_group_with_nested_options()]] - code - backend/tests/Feature/Catalog/OptionGroupTest.php
- [[.test_can_delete_empty_category()]] - code - backend/tests/Feature/Catalog/CategoryTest.php
- [[.test_can_list_active_orders_in_queue()]] - code - backend/tests/Feature/OrderQueueTest.php
- [[.test_can_update_option_group_and_sync_options()]] - code - backend/tests/Feature/Catalog/OptionGroupTest.php
- [[.test_cashier_and_admin_cannot_download_backup()]] - code - backend/tests/Feature/SystemBackupTest.php
- [[.test_cashier_cannot_access_user_management()]] - code - backend/tests/Feature/UserManagementTest.php
- [[.test_idempotency_prevents_duplicate_sales()]] - code - backend/tests/Unit/SaleTransactionTest.php
- [[.test_sale_creation_is_transactional()]] - code - backend/tests/Unit/SaleTransactionTest.php
- [[.test_super_admin_can_download_compressed_backup_and_creates_audit_log()]] - code - backend/tests/Feature/SystemBackupTest.php
- [[.test_super_admin_requires_valid_password()]] - code - backend/tests/Feature/SystemBackupTest.php
- [[.test_the_application_returns_a_successful_response()]] - code - backend/tests/Feature/ExampleTest.php
- [[.test_user_validation_rules()]] - code - backend/tests/Feature/UserManagementTest.php
- [[.toArray()_6]] - code - backend/app/Http/Resources/SaleResource.php
- [[AdminSaleManagementTest.php]] - code - backend/tests/Feature/AdminSaleManagementTest.php
- [[CashRegisterSessionTest.php]] - code - backend/tests/Feature/CashRegisterSessionTest.php
- [[CategoryTest]] - code - backend/tests/Feature/Catalog/CategoryTest.php
- [[ExampleTest]] - code - backend/tests/Feature/ExampleTest.php
- [[ExampleTest.php]] - code - backend/tests/Feature/ExampleTest.php
- [[IlluminateFoundationAuthUser]] - code
- [[IlluminateFoundationTestingRefreshDatabase]] - code
- [[IlluminateFoundationTestingTestCase]] - code
- [[OptionGroupTest]] - code - backend/tests/Feature/Catalog/OptionGroupTest.php
- [[OrderQueueTest]] - code - backend/tests/Feature/OrderQueueTest.php
- [[OrderQueueTest.php]] - code - backend/tests/Feature/OrderQueueTest.php
- [[Sale.php]] - code - backend/app/Models/Sale.php
- [[SaleSyncTest]] - code - backend/tests/Feature/SaleSyncTest.php
- [[SaleSyncTest.php]] - code - backend/tests/Feature/SaleSyncTest.php
- [[SaleTransactionTest]] - code - backend/tests/Unit/SaleTransactionTest.php
- [[SaleTransactionTest.php]] - code - backend/tests/Unit/SaleTransactionTest.php
- [[SystemBackupTest]] - code - backend/tests/Feature/SystemBackupTest.php
- [[SystemBackupTest.php]] - code - backend/tests/Feature/SystemBackupTest.php
- [[TestCase]] - code - backend/tests/TestCase.php
- [[TestCase.php]] - code - backend/tests/TestCase.php
- [[User]] - code - backend/app/Models/User.php
- [[UserManagementTest]] - code - backend/tests/Feature/UserManagementTest.php
- [[UserManagementTest.php]] - code - backend/tests/Feature/UserManagementTest.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/tests/Feature_toArray_parent
SORT file.name ASC
```

## Connections to other communities
- 20 edges to [[_COMMUNITY_appModels Category.php, .scopeOrdered()]]
- 20 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 9 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 7 edges to [[_COMMUNITY_appHttpControllers CashRegisterSessionCont]]
- 7 edges to [[_COMMUNITY_testsFeature Customer.php, KdsApiTest.php]]
- 5 edges to [[_COMMUNITY_appModels KdsController.php, KdsController]]
- 4 edges to [[_COMMUNITY_appModels .movements(), .children()]]
- 4 edges to [[_COMMUNITY_appHttpControllers UserController.php, Req]]
- 4 edges to [[_COMMUNITY_testsFeature AdminSaleManagementTest, .test]]
- 2 edges to [[_COMMUNITY_appRepositories .paginated(), UserRepositor]]
- 1 edge to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 1 edge to [[_COMMUNITY_appHttpResources CategoryResource.php, Cus]]
- 1 edge to [[_COMMUNITY_appHttpControllers AuditLogController.php,]]
- 1 edge to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 1 edge to [[_COMMUNITY_appRepositories AppServiceProvider.php, App]]

## Top bridge nodes
- [[User]] - degree 47, connects to 10 communities
- [[Sale.php]] - degree 14, connects to 6 communities
- [[TestCase]] - degree 26, connects to 5 communities
- [[IlluminateFoundationTestingRefreshDatabase]] - degree 22, connects to 5 communities
- [[AdminSaleManagementTest.php]] - degree 10, connects to 5 communities