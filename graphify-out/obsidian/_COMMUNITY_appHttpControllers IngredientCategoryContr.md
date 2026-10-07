---
type: community
cohesion: 0.15
members: 21
---

# app/Http/Controllers: IngredientCategoryContr

**Cohesion:** 0.15 - loosely connected
**Members:** 21 nodes

## Members
- [[.__construct()_2]] - code - backend/app/Http/Controllers/IngredientCategoryController.php
- [[.all()]] - code - backend/app/Repositories/IngredientCategoryRepository.php
- [[.authorize()_3]] - code - backend/app/Http/Requests/IngredientCategoryRequest.php
- [[.create()_1]] - code - backend/app/Repositories/IngredientCategoryRepository.php
- [[.delete()_1]] - code - backend/app/Repositories/IngredientCategoryRepository.php
- [[.destroy()_2]] - code - backend/app/Http/Controllers/IngredientCategoryController.php
- [[.index()_5]] - code - backend/app/Http/Controllers/IngredientCategoryController.php
- [[.ingredients()]] - code - backend/app/Models/IngredientCategory.php
- [[.messages()_3]] - code - backend/app/Http/Requests/IngredientCategoryRequest.php
- [[.rules()_3]] - code - backend/app/Http/Requests/IngredientCategoryRequest.php
- [[.show()_3]] - code - backend/app/Http/Controllers/IngredientCategoryController.php
- [[.store()_3]] - code - backend/app/Http/Controllers/IngredientCategoryController.php
- [[.update()_2]] - code - backend/app/Http/Controllers/IngredientCategoryController.php
- [[.update()_11]] - code - backend/app/Repositories/IngredientCategoryRepository.php
- [[IngredientCategory]] - code - backend/app/Models/IngredientCategory.php
- [[IngredientCategoryController]] - code - backend/app/Http/Controllers/IngredientCategoryController.php
- [[IngredientCategoryController.php]] - code - backend/app/Http/Controllers/IngredientCategoryController.php
- [[IngredientCategoryRepository]] - code - backend/app/Repositories/IngredientCategoryRepository.php
- [[IngredientCategoryRepository.php]] - code - backend/app/Repositories/IngredientCategoryRepository.php
- [[IngredientCategoryRequest]] - code - backend/app/Http/Requests/IngredientCategoryRequest.php
- [[IngredientCategoryRequest.php]] - code - backend/app/Http/Requests/IngredientCategoryRequest.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Http/Controllers_IngredientCategoryContr
SORT file.name ASC
```

## Connections to other communities
- 7 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 3 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 2 edges to [[_COMMUNITY_appHttpRequests AdminSaleRequest.php, Admi]]
- 2 edges to [[_COMMUNITY_appRepositories AppServiceProvider.php, App]]
- 1 edge to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 1 edge to [[_COMMUNITY_appModels Category.php, .scopeOrdered()]]
- 1 edge to [[_COMMUNITY_appModels .movements(), .children()]]

## Top bridge nodes
- [[IngredientCategory]] - degree 15, connects to 3 communities
- [[IngredientCategoryController]] - degree 9, connects to 1 community
- [[IngredientCategoryRequest]] - degree 8, connects to 1 community
- [[IngredientCategoryController.php]] - degree 5, connects to 1 community
- [[.update()_2]] - degree 4, connects to 1 community