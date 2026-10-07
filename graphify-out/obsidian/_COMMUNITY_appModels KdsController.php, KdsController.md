---
type: community
cohesion: 0.16
members: 19
---

# app/Models: KdsController.php, KdsController

**Cohesion:** 0.16 - loosely connected
**Members:** 19 nodes

## Members
- [[.cashier()_1]] - code - backend/app/Models/Sale.php
- [[.casts()_10]] - code - backend/app/Models/Sale.php
- [[.customer()]] - code - backend/app/Models/Sale.php
- [[.deliveryOrder()]] - code - backend/app/Models/Sale.php
- [[.editedByUser()]] - code - backend/app/Models/Sale.php
- [[.formatKdsOrder()]] - code - backend/app/Http/Controllers/KdsController.php
- [[.index()_8]] - code - backend/app/Http/Controllers/KdsController.php
- [[.isPending()]] - code - backend/app/Models/Sale.php
- [[.isVoided()]] - code - backend/app/Models/Sale.php
- [[.stats()]] - code - backend/app/Http/Controllers/KdsController.php
- [[.stream()]] - code - backend/app/Http/Controllers/KdsController.php
- [[.updateStatus()]] - code - backend/app/Http/Controllers/KdsController.php
- [[.voidedByUser()]] - code - backend/app/Models/Sale.php
- [[IlluminateDatabaseEloquentConcernsHasUuids]] - code
- [[IlluminateDatabaseEloquentRelationsHasOne]] - code
- [[KdsController]] - code - backend/app/Http/Controllers/KdsController.php
- [[KdsController.php]] - code - backend/app/Http/Controllers/KdsController.php
- [[Sale]] - code - backend/app/Models/Sale.php
- [[SymfonyComponentHttpFoundationStreamedResponse]] - code

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Models_KdsControllerphp_KdsController
SORT file.name ASC
```

## Connections to other communities
- 10 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 7 edges to [[_COMMUNITY_appHttpControllers AuditLogController.php,]]
- 7 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 7 edges to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 5 edges to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 3 edges to [[_COMMUNITY_appHttpControllers CashRegisterSessionCont]]
- 3 edges to [[_COMMUNITY_testsFeature Customer.php, KdsApiTest.php]]
- 2 edges to [[_COMMUNITY_appModels .movements(), .children()]]
- 1 edge to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 1 edge to [[_COMMUNITY_appModels Category.php, .scopeOrdered()]]
- 1 edge to [[_COMMUNITY_appRepositories AppServiceProvider.php, App]]
- 1 edge to [[_COMMUNITY_appHttpControllers LoyaltyConfigController]]
- 1 edge to [[_COMMUNITY_testsFeature AdminSaleManagementTest, .test]]

## Top bridge nodes
- [[Sale]] - degree 49, connects to 13 communities
- [[KdsController.php]] - degree 5, connects to 2 communities
- [[.index()_8]] - degree 5, connects to 2 communities
- [[.updateStatus()]] - degree 5, connects to 2 communities
- [[KdsController]] - degree 7, connects to 1 community