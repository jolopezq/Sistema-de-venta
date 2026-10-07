---
type: community
cohesion: 0.22
members: 9
---

# tests/Feature: AdminSaleManagementTest, .test

**Cohesion:** 0.22 - loosely connected
**Members:** 9 nodes

## Members
- [[.test_admin_can_create_pedidosya_sale_with_delivery_price()]] - code - backend/tests/Feature/AdminSaleManagementTest.php
- [[.test_cannot_edit_voided_sale()]] - code - backend/tests/Feature/AdminSaleManagementTest.php
- [[.test_cashier_cannot_access_admin_sales_endpoints_but_admin_can()]] - code - backend/tests/Feature/AdminSaleManagementTest.php
- [[.test_edit_reason_is_mandatory()]] - code - backend/tests/Feature/AdminSaleManagementTest.php
- [[.test_pedidosya_sale_with_surcharge_saves_correctly()]] - code - backend/tests/Feature/AdminSaleManagementTest.php
- [[.test_super_admin_can_create_retroactive_sale()]] - code - backend/tests/Feature/AdminSaleManagementTest.php
- [[.test_super_admin_can_create_retroactive_sale_with_pedidosya_source()]] - code - backend/tests/Feature/AdminSaleManagementTest.php
- [[.test_super_admin_can_edit_existing_sale_and_recalculate_stock()]] - code - backend/tests/Feature/AdminSaleManagementTest.php
- [[AdminSaleManagementTest]] - code - backend/tests/Feature/AdminSaleManagementTest.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/tests/Feature_AdminSaleManagementTest_test
SORT file.name ASC
```

## Connections to other communities
- 4 edges to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 2 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 1 edge to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 1 edge to [[_COMMUNITY_appModels KdsController.php, KdsController]]

## Top bridge nodes
- [[AdminSaleManagementTest]] - degree 15, connects to 3 communities
- [[.test_cannot_edit_voided_sale()]] - degree 2, connects to 1 community