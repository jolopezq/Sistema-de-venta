---
type: community
cohesion: 0.17
members: 16
---

# app/Services: InventoryMovementController, ._

**Cohesion:** 0.17 - loosely connected
**Members:** 16 nodes

## Members
- [[.__construct()_4]] - code - backend/app/Http/Controllers/InventoryMovementController.php
- [[.casts()_5]] - code - backend/app/Models/InventoryMovement.php
- [[.deductFromOptionRecipes()]] - code - backend/app/Services/InventoryService.php
- [[.deductFromRecipe()]] - code - backend/app/Services/InventoryService.php
- [[.index()_7]] - code - backend/app/Http/Controllers/InventoryMovementController.php
- [[.ingredient()]] - code - backend/app/Models/InventoryMovement.php
- [[.performedByUser()]] - code - backend/app/Models/InventoryMovement.php
- [[.recordManualMovement()]] - code - backend/app/Services/InventoryService.php
- [[.recordRestock()]] - code - backend/app/Services/InventoryService.php
- [[.reference()]] - code - backend/app/Models/InventoryMovement.php
- [[.store()_5]] - code - backend/app/Http/Controllers/InventoryMovementController.php
- [[Collection]] - code
- [[InventoryMovement]] - code - backend/app/Models/InventoryMovement.php
- [[InventoryMovementController]] - code - backend/app/Http/Controllers/InventoryMovementController.php
- [[InventoryService]] - code - backend/app/Services/InventoryService.php
- [[InventoryService.php]] - code - backend/app/Services/InventoryService.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Services_InventoryMovementController__
SORT file.name ASC
```

## Connections to other communities
- 8 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 5 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 5 edges to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 3 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 3 edges to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 1 edge to [[_COMMUNITY_appHttpRequests AdminSaleRequest.php, Admi]]
- 1 edge to [[_COMMUNITY_appHttpControllers LoyaltyConfigController]]

## Top bridge nodes
- [[InventoryMovement]] - degree 15, connects to 4 communities
- [[.store()_5]] - degree 4, connects to 3 communities
- [[InventoryService.php]] - degree 4, connects to 3 communities
- [[InventoryService]] - degree 10, connects to 2 communities
- [[.index()_7]] - degree 4, connects to 2 communities