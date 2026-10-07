---
type: community
cohesion: 0.20
members: 12
---

# app/Repositories: AppServiceProvider.php, App

**Cohesion:** 0.20 - loosely connected
**Members:** 12 nodes

## Members
- [[.allActive()]] - code - backend/app/Repositories/ProductRepository.php
- [[.allOrdered()]] - code - backend/app/Repositories/CategoryRepository.php
- [[.boot()]] - code - backend/app/Providers/AppServiceProvider.php
- [[.register()]] - code - backend/app/Providers/AppServiceProvider.php
- [[AppServiceProvider]] - code - backend/app/Providers/AppServiceProvider.php
- [[AppServiceProvider.php]] - code - backend/app/Providers/AppServiceProvider.php
- [[CategoryRepository.php]] - code - backend/app/Repositories/CategoryRepository.php
- [[IlluminateDatabaseEloquentCollection]] - code
- [[IlluminateSupportServiceProvider]] - code
- [[ProductRepository.php]] - code - backend/app/Repositories/ProductRepository.php
- [[SaleRepository.php]] - code - backend/app/Repositories/SaleRepository.php
- [[providers.php]] - code - backend/bootstrap/providers.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Repositories_AppServiceProviderphp_App
SORT file.name ASC
```

## Connections to other communities
- 4 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 2 edges to [[_COMMUNITY_appHttpControllers CatalogController, .__c]]
- 2 edges to [[_COMMUNITY_appHttpControllers IngredientCategoryContr]]
- 2 edges to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 2 edges to [[_COMMUNITY_appRepositories ProductRepository, .create(]]
- 1 edge to [[_COMMUNITY_appModels KdsController.php, KdsController]]
- 1 edge to [[_COMMUNITY_appHttpControllers CustomerController.php,]]
- 1 edge to [[_COMMUNITY_appRepositories .paginated(), UserRepositor]]
- 1 edge to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 1 edge to [[_COMMUNITY_testsFeature .toArray(), .parent()]]

## Top bridge nodes
- [[ProductRepository.php]] - degree 5, connects to 3 communities
- [[SaleRepository.php]] - degree 4, connects to 3 communities
- [[IlluminateDatabaseEloquentCollection]] - degree 8, connects to 2 communities
- [[CategoryRepository.php]] - degree 4, connects to 2 communities
- [[.allOrdered()]] - degree 3, connects to 2 communities