---
source_file: "backend/app/Models/Sale.php"
type: "code"
community: "app/Models: KdsController.php, KdsController"
location: "L23"
tags:
  - graphify/code
  - graphify/EXTRACTED
  - community/app/Models_KdsControllerphp_KdsController
---

# Sale

## Connections
- [[.adminCreateSale()]] - `references` [EXTRACTED]
- [[.adminUpdate()]] - `references` [EXTRACTED]
- [[.adminUpdateSale()]] - `references` [EXTRACTED]
- [[.calculateSessionStats()]] - `calls` [INFERRED]
- [[.cashier()_1]] - `method` [EXTRACTED]
- [[.casts()_10]] - `method` [EXTRACTED]
- [[.create()_4]] - `references` [EXTRACTED]
- [[.customer()]] - `method` [EXTRACTED]
- [[.deliveryOrder()]] - `method` [EXTRACTED]
- [[.editedByUser()]] - `method` [EXTRACTED]
- [[.existsByUuid()]] - `calls` [EXTRACTED]
- [[.formatKdsOrder()]] - `references` [EXTRACTED]
- [[.index()_8]] - `calls` [EXTRACTED]
- [[.index()_11]] - `calls` [EXTRACTED]
- [[.index()_14]] - `calls` [EXTRACTED]
- [[.insertSaleItems()]] - `references` [EXTRACTED]
- [[.isPending()]] - `method` [EXTRACTED]
- [[.isVoided()]] - `method` [EXTRACTED]
- [[.items()]] - `method` [EXTRACTED]
- [[.payments()]] - `method` [EXTRACTED]
- [[.processSale()]] - `calls` [EXTRACTED]
- [[.show()_9]] - `references` [EXTRACTED]
- [[.stats()]] - `calls` [EXTRACTED]
- [[.stream()]] - `calls` [EXTRACTED]
- [[.test_can_advance_order_status_in_queue()]] - `calls` [INFERRED]
- [[.test_can_list_active_orders_in_queue()]] - `calls` [INFERRED]
- [[.test_cannot_edit_voided_sale()]] - `calls` [INFERRED]
- [[.test_cashier_can_get_active_session_with_realtime_stats()]] - `calls` [INFERRED]
- [[.test_kds_order_status_progression_with_timestamps()]] - `calls` [INFERRED]
- [[.test_kds_stats_endpoint_returns_metrics()]] - `calls` [INFERRED]
- [[.test_kitchen_user_can_access_kds_orders_list()]] - `calls` [INFERRED]
- [[.test_sales_index_can_filter_by_date_and_payment_method()]] - `calls` [INFERRED]
- [[.updateLoyaltyPoints()]] - `references` [EXTRACTED]
- [[.updateStatus()]] - `references` [EXTRACTED]
- [[.updateStatus()_1]] - `references` [EXTRACTED]
- [[.voidSale()]] - `references` [EXTRACTED]
- [[.voidSale()_1]] - `references` [EXTRACTED]
- [[.voidedByUser()]] - `method` [EXTRACTED]
- [[AppTraitsAuditable]] - `mixes_in` [EXTRACTED]
- [[IlluminateDatabaseEloquentConcernsHasUuids]] - `mixes_in` [EXTRACTED]
- [[IlluminateDatabaseEloquentFactoriesHasFactory]] - `mixes_in` [EXTRACTED]
- [[IlluminateDatabaseEloquentModel]] - `inherits` [EXTRACTED]
- [[IlluminateDatabaseEloquentSoftDeletes]] - `mixes_in` [EXTRACTED]
- [[KdsController.php]] - `imports` [EXTRACTED]
- [[OrderQueueController.php]] - `imports` [EXTRACTED]
- [[Sale.php]] - `contains` [EXTRACTED]
- [[SaleController.php]] - `imports` [EXTRACTED]
- [[SaleRepository.php]] - `imports` [EXTRACTED]
- [[SaleSyncService.php]] - `imports` [EXTRACTED]

#graphify/code #graphify/EXTRACTED #community/app/Models_KdsControllerphp_KdsController