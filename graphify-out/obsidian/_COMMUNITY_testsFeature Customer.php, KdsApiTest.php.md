---
type: community
cohesion: 0.29
members: 7
---

# tests/Feature: Customer.php, KdsApiTest.php

**Cohesion:** 0.29 - loosely connected
**Members:** 7 nodes

## Members
- [[.test_kds_order_status_progression_with_timestamps()]] - code - backend/tests/Feature/KdsApiTest.php
- [[.test_kds_stats_endpoint_returns_metrics()]] - code - backend/tests/Feature/KdsApiTest.php
- [[.test_kitchen_user_can_access_kds_orders_list()]] - code - backend/tests/Feature/KdsApiTest.php
- [[.test_unauthenticated_request_is_rejected()]] - code - backend/tests/Feature/KdsApiTest.php
- [[Customer.php]] - code - backend/app/Models/Customer.php
- [[KdsApiTest]] - code - backend/tests/Feature/KdsApiTest.php
- [[KdsApiTest.php]] - code - backend/tests/Feature/KdsApiTest.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/tests/Feature_Customerphp_KdsApiTestphp
SORT file.name ASC
```

## Connections to other communities
- 7 edges to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 6 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 5 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 3 edges to [[_COMMUNITY_appModels KdsController.php, KdsController]]
- 2 edges to [[_COMMUNITY_appModels Category.php, .scopeOrdered()]]
- 2 edges to [[_COMMUNITY_appHttpControllers OptionController.php, O]]
- 1 edge to [[_COMMUNITY_appHttpControllers CustomerController.php,]]
- 1 edge to [[_COMMUNITY_appModels .movements(), .children()]]

## Top bridge nodes
- [[KdsApiTest.php]] - degree 12, connects to 5 communities
- [[Customer.php]] - degree 6, connects to 4 communities
- [[KdsApiTest]] - degree 12, connects to 3 communities
- [[.test_kitchen_user_can_access_kds_orders_list()]] - degree 4, connects to 2 communities
- [[.test_kds_order_status_progression_with_timestamps()]] - degree 2, connects to 1 community