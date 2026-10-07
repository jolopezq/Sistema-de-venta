---
type: community
cohesion: 0.21
members: 18
---

# app/Http/Controllers: AuditLogController.php,

**Cohesion:** 0.21 - loosely connected
**Members:** 18 nodes

## Members
- [[.buildQuery()]] - code - backend/app/Http/Controllers/AuditLogController.php
- [[.export()]] - code - backend/app/Http/Controllers/AuditLogController.php
- [[.handle()_1]] - code - backend/app/Http/Middleware/EnsureUserIsAdmin.php
- [[.handle()_2]] - code - backend/app/Http/Middleware/EnsureUserIsSuperAdmin.php
- [[.index()]] - code - backend/app/Http/Controllers/AuditLogController.php
- [[.index()_11]] - code - backend/app/Http/Controllers/OrderQueueController.php
- [[.updateStatus()_1]] - code - backend/app/Http/Controllers/OrderQueueController.php
- [[AuditLogController]] - code - backend/app/Http/Controllers/AuditLogController.php
- [[AuditLogController.php]] - code - backend/app/Http/Controllers/AuditLogController.php
- [[Closure]] - code
- [[EnsureUserIsAdmin]] - code - backend/app/Http/Middleware/EnsureUserIsAdmin.php
- [[EnsureUserIsAdmin.php]] - code - backend/app/Http/Middleware/EnsureUserIsAdmin.php
- [[EnsureUserIsSuperAdmin]] - code - backend/app/Http/Middleware/EnsureUserIsSuperAdmin.php
- [[EnsureUserIsSuperAdmin.php]] - code - backend/app/Http/Middleware/EnsureUserIsSuperAdmin.php
- [[IlluminateHttpRequest]] - code
- [[OrderQueueController]] - code - backend/app/Http/Controllers/OrderQueueController.php
- [[OrderQueueController.php]] - code - backend/app/Http/Controllers/OrderQueueController.php
- [[SymfonyComponentHttpFoundationResponse]] - code

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Http/Controllers_AuditLogControllerphp
SORT file.name ASC
```

## Connections to other communities
- 13 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 7 edges to [[_COMMUNITY_appModels KdsController.php, KdsController]]
- 6 edges to [[_COMMUNITY_appHttpControllers CashRegisterSessionCont]]
- 5 edges to [[_COMMUNITY_appHttpControllers OptionGroupController.p]]
- 4 edges to [[_COMMUNITY_appHttpControllers IngredientController.ph]]
- 3 edges to [[_COMMUNITY_appHttpControllers SystemBackupController.]]
- 3 edges to [[_COMMUNITY_appHttpResources CategoryResource.php, Cus]]
- 2 edges to [[_COMMUNITY_appHttpControllers LoyaltyConfigController]]
- 2 edges to [[_COMMUNITY_appHttpControllers OptionRecipeController.]]
- 2 edges to [[_COMMUNITY_appHttpControllers ProductController.php,]]
- 2 edges to [[_COMMUNITY_appHttpControllers OptionController.php, O]]
- 2 edges to [[_COMMUNITY_appHttpControllers UserController.php, Req]]
- 1 edge to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 1 edge to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 1 edge to [[_COMMUNITY_appHttpControllers CategoryController.php,]]
- 1 edge to [[_COMMUNITY_appHttpControllers CustomerController.php,]]
- 1 edge to [[_COMMUNITY_testsFeature .toArray(), .parent()]]

## Top bridge nodes
- [[IlluminateHttpRequest]] - degree 55, connects to 15 communities
- [[AuditLogController.php]] - degree 4, connects to 2 communities
- [[OrderQueueController.php]] - degree 4, connects to 2 communities
- [[.index()_11]] - degree 4, connects to 2 communities
- [[.updateStatus()_1]] - degree 4, connects to 2 communities