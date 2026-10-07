---
type: community
cohesion: 0.33
members: 7
---

# app/Http/Controllers: LoyaltyConfigController

**Cohesion:** 0.33 - loosely connected
**Members:** 7 nodes

## Members
- [[.casts()_6]] - code - backend/app/Models/LoyaltyConfig.php
- [[.show()_5]] - code - backend/app/Http/Controllers/LoyaltyConfigController.php
- [[.update()_4]] - code - backend/app/Http/Controllers/LoyaltyConfigController.php
- [[.voidSale()_1]] - code - backend/app/Services/SaleSyncService.php
- [[LoyaltyConfig]] - code - backend/app/Models/LoyaltyConfig.php
- [[LoyaltyConfigController]] - code - backend/app/Http/Controllers/LoyaltyConfigController.php
- [[LoyaltyConfigController.php]] - code - backend/app/Http/Controllers/LoyaltyConfigController.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Http/Controllers_LoyaltyConfigController
SORT file.name ASC
```

## Connections to other communities
- 5 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 4 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 3 edges to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 2 edges to [[_COMMUNITY_appHttpControllers AuditLogController.php,]]
- 1 edge to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 1 edge to [[_COMMUNITY_appServices InventoryMovementController, ._]]
- 1 edge to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 1 edge to [[_COMMUNITY_databasefactories .active(), Auditable.php]]
- 1 edge to [[_COMMUNITY_appModels KdsController.php, KdsController]]

## Top bridge nodes
- [[.voidSale()_1]] - degree 6, connects to 5 communities
- [[LoyaltyConfig]] - degree 10, connects to 4 communities
- [[LoyaltyConfigController.php]] - degree 5, connects to 3 communities
- [[.update()_4]] - degree 4, connects to 2 communities
- [[LoyaltyConfigController]] - degree 4, connects to 1 community