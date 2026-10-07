---
source_file: "backend/app/Models/User.php"
type: "code"
community: "tests/Feature: .toArray(), .parent()"
location: "L18"
tags:
  - graphify/code
  - graphify/EXTRACTED
  - community/tests/Feature_toArray_parent
---

# User

## Connections
- [[.cashRegisterSessions()]] - `method` [EXTRACTED]
- [[.casts()_13]] - `method` [EXTRACTED]
- [[.create()_5]] - `references` [EXTRACTED]
- [[.delete()_4]] - `references` [EXTRACTED]
- [[.find()_2]] - `references` [EXTRACTED]
- [[.inventoryMovements()_1]] - `method` [EXTRACTED]
- [[.isAdmin()]] - `method` [EXTRACTED]
- [[.isCashier()]] - `method` [EXTRACTED]
- [[.isKitchen()]] - `method` [EXTRACTED]
- [[.isSuperAdmin()]] - `method` [EXTRACTED]
- [[.login()]] - `calls` [INFERRED]
- [[.paginated()_2]] - `calls` [EXTRACTED]
- [[.run()]] - `calls` [INFERRED]
- [[.sales()_1]] - `method` [EXTRACTED]
- [[.setUp()]] - `calls` [EXTRACTED]
- [[.setUp()_1]] - `calls` [EXTRACTED]
- [[.setUp()_2]] - `calls` [INFERRED]
- [[.setUp()_3]] - `calls` [INFERRED]
- [[.setUp()_4]] - `calls` [INFERRED]
- [[.setUp()_5]] - `calls` [EXTRACTED]
- [[.setUp()_6]] - `calls` [EXTRACTED]
- [[.test_admin_can_manage_users()]] - `calls` [INFERRED]
- [[.test_cashier_and_admin_cannot_download_backup()]] - `calls` [INFERRED]
- [[.test_cashier_cannot_access_user_management()]] - `calls` [INFERRED]
- [[.test_idempotency_prevents_duplicate_sales()]] - `calls` [INFERRED]
- [[.test_it_syncs_offline_sales_and_deducts_inventory_and_is_idempotent()]] - `calls` [INFERRED]
- [[.test_sale_creation_is_transactional()]] - `calls` [INFERRED]
- [[.test_super_admin_can_download_compressed_backup_and_creates_audit_log()]] - `calls` [INFERRED]
- [[.test_super_admin_requires_valid_password()]] - `calls` [INFERRED]
- [[.test_user_validation_rules()]] - `calls` [INFERRED]
- [[.update()_14]] - `references` [EXTRACTED]
- [[AdminSaleManagementTest]] - `references` [EXTRACTED]
- [[AdminSaleManagementTest.php]] - `imports` [EXTRACTED]
- [[AppTraitsAuditable]] - `mixes_in` [EXTRACTED]
- [[CashRegisterSessionTest]] - `references` [EXTRACTED]
- [[CashRegisterSessionTest.php]] - `imports` [EXTRACTED]
- [[IlluminateDatabaseEloquentFactoriesHasFactory]] - `mixes_in` [EXTRACTED]
- [[IlluminateDatabaseEloquentSoftDeletes]] - `mixes_in` [EXTRACTED]
- [[IlluminateFoundationAuthUser]] - `inherits` [EXTRACTED]
- [[IlluminateNotificationsNotifiable]] - `mixes_in` [EXTRACTED]
- [[KdsApiTest]] - `references` [EXTRACTED]
- [[KdsApiTest.php]] - `imports` [EXTRACTED]
- [[LaravelSanctumHasApiTokens]] - `mixes_in` [EXTRACTED]
- [[OrderQueueTest]] - `references` [EXTRACTED]
- [[OrderQueueTest.php]] - `imports` [EXTRACTED]
- [[User.php]] - `contains` [EXTRACTED]
- [[UserRepository.php]] - `imports` [EXTRACTED]

#graphify/code #graphify/EXTRACTED #community/tests/Feature_toArray_parent