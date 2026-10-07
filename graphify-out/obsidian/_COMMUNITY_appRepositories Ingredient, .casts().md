---
type: community
cohesion: 0.24
members: 13
---

# app/Repositories: Ingredient, .casts()

**Cohesion:** 0.24 - loosely connected
**Members:** 13 nodes

## Members
- [[.all()_1]] - code - backend/app/Repositories/IngredientRepository.php
- [[.casts()_4]] - code - backend/app/Models/Ingredient.php
- [[.category()]] - code - backend/app/Models/Ingredient.php
- [[.create()_2]] - code - backend/app/Repositories/IngredientRepository.php
- [[.delete()_2]] - code - backend/app/Repositories/IngredientRepository.php
- [[.find()_1]] - code - backend/app/Repositories/IngredientRepository.php
- [[.findWithMovements()]] - code - backend/app/Repositories/IngredientRepository.php
- [[.isBelowMinimumStock()]] - code - backend/app/Models/Ingredient.php
- [[.paginated()]] - code - backend/app/Repositories/IngredientRepository.php
- [[.update()_12]] - code - backend/app/Repositories/IngredientRepository.php
- [[Ingredient]] - code - backend/app/Models/Ingredient.php
- [[IngredientRepository]] - code - backend/app/Repositories/IngredientRepository.php
- [[IngredientRepository.php]] - code - backend/app/Repositories/IngredientRepository.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Repositories_Ingredient_casts
SORT file.name ASC
```

## Connections to other communities
- 5 edges to [[_COMMUNITY_appServices InventoryMovementController, ._]]
- 4 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 4 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 3 edges to [[_COMMUNITY_appHttpControllers IngredientController.ph]]
- 2 edges to [[_COMMUNITY_appModels .movements(), .children()]]
- 2 edges to [[_COMMUNITY_appRepositories .paginated(), UserRepositor]]
- 2 edges to [[_COMMUNITY_appRepositories AppServiceProvider.php, App]]
- 1 edge to [[_COMMUNITY_appHttpControllers CatalogController, .__c]]
- 1 edge to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 1 edge to [[_COMMUNITY_appModels Category.php, .scopeOrdered()]]
- 1 edge to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 1 edge to [[_COMMUNITY_appHttpControllers LoyaltyConfigController]]
- 1 edge to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 1 edge to [[_COMMUNITY_testsFeature AdminSaleManagementTest, .test]]

## Top bridge nodes
- [[Ingredient]] - degree 32, connects to 11 communities
- [[IngredientRepository.php]] - degree 4, connects to 2 communities
- [[IngredientRepository]] - degree 11, connects to 1 community
- [[.all()_1]] - degree 3, connects to 1 community
- [[.paginated()]] - degree 3, connects to 1 community