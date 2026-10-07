---
type: community
cohesion: 0.14
members: 14
---

# app/Models: .movements(), .children()

**Cohesion:** 0.14 - loosely connected
**Members:** 14 nodes

## Members
- [[.cashRegisterSessions()]] - code - backend/app/Models/User.php
- [[.children()]] - code - backend/app/Models/Category.php
- [[.inventoryMovements()]] - code - backend/app/Models/Ingredient.php
- [[.inventoryMovements()_1]] - code - backend/app/Models/User.php
- [[.items()]] - code - backend/app/Models/Sale.php
- [[.movements()_1]] - code - backend/app/Models/CashRegisterSession.php
- [[.payments()]] - code - backend/app/Models/Sale.php
- [[.products()]] - code - backend/app/Models/Category.php
- [[.recipes()]] - code - backend/app/Models/Ingredient.php
- [[.recipes()_2]] - code - backend/app/Models/Product.php
- [[.saleItems()]] - code - backend/app/Models/Product.php
- [[.sales()_1]] - code - backend/app/Models/User.php
- [[.variants()]] - code - backend/app/Models/Product.php
- [[IlluminateDatabaseEloquentRelationsHasMany]] - code

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Models_movements_children
SORT file.name ASC
```

## Connections to other communities
- 5 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 4 edges to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 3 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 3 edges to [[_COMMUNITY_appModels Category.php, .scopeOrdered()]]
- 2 edges to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 2 edges to [[_COMMUNITY_appModels KdsController.php, KdsController]]
- 1 edge to [[_COMMUNITY_appHttpControllers CashRegisterSessionCont]]
- 1 edge to [[_COMMUNITY_testsFeature Customer.php, KdsApiTest.php]]
- 1 edge to [[_COMMUNITY_appHttpControllers CustomerController.php,]]
- 1 edge to [[_COMMUNITY_appHttpControllers IngredientCategoryContr]]

## Top bridge nodes
- [[IlluminateDatabaseEloquentRelationsHasMany]] - degree 23, connects to 6 communities
- [[.movements()_1]] - degree 2, connects to 1 community
- [[.children()]] - degree 2, connects to 1 community
- [[.products()]] - degree 2, connects to 1 community
- [[.inventoryMovements()]] - degree 2, connects to 1 community