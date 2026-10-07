---
type: community
cohesion: 0.48
members: 7
---

# app/Repositories: ProductRepository, .create(

**Cohesion:** 0.48 - moderately connected
**Members:** 7 nodes

## Members
- [[.create()_3]] - code - backend/app/Repositories/ProductRepository.php
- [[.delete()_3]] - code - backend/app/Repositories/ProductRepository.php
- [[.findWithRecipes()]] - code - backend/app/Repositories/ProductRepository.php
- [[.syncExcludedOptions()]] - code - backend/app/Repositories/ProductRepository.php
- [[.syncOptionGroups()]] - code - backend/app/Repositories/ProductRepository.php
- [[.update()_13]] - code - backend/app/Repositories/ProductRepository.php
- [[ProductRepository]] - code - backend/app/Repositories/ProductRepository.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Repositories_ProductRepository_create
SORT file.name ASC
```

## Connections to other communities
- 6 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 3 edges to [[_COMMUNITY_appHttpControllers ProductController.php,]]
- 2 edges to [[_COMMUNITY_appHttpControllers CatalogController, .__c]]
- 2 edges to [[_COMMUNITY_appRepositories AppServiceProvider.php, App]]
- 2 edges to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 1 edge to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 1 edge to [[_COMMUNITY_appRepositories .paginated(), UserRepositor]]
- 1 edge to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]

## Top bridge nodes
- [[ProductRepository]] - degree 18, connects to 7 communities
- [[.create()_3]] - degree 4, connects to 1 community
- [[.syncExcludedOptions()]] - degree 4, connects to 1 community
- [[.syncOptionGroups()]] - degree 4, connects to 1 community
- [[.update()_13]] - degree 4, connects to 1 community