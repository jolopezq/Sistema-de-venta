---
type: community
cohesion: 0.29
members: 8
---

# app/Http/Controllers: CatalogController, .__c

**Cohesion:** 0.29 - loosely connected
**Members:** 8 nodes

## Members
- [[.__construct()]] - code - backend/app/Http/Controllers/CatalogController.php
- [[.__construct()_1]] - code - backend/app/Http/Controllers/CategoryController.php
- [[.create()]] - code - backend/app/Repositories/CategoryRepository.php
- [[.delete()]] - code - backend/app/Repositories/CategoryRepository.php
- [[.index()_2]] - code - backend/app/Http/Controllers/CatalogController.php
- [[.update()_10]] - code - backend/app/Repositories/CategoryRepository.php
- [[CatalogController]] - code - backend/app/Http/Controllers/CatalogController.php
- [[CategoryRepository]] - code - backend/app/Repositories/CategoryRepository.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Http/Controllers_CatalogController___c
SORT file.name ASC
```

## Connections to other communities
- 4 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 4 edges to [[_COMMUNITY_appHttpControllers CategoryController.php,]]
- 3 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 2 edges to [[_COMMUNITY_appRepositories ProductRepository, .create(]]
- 2 edges to [[_COMMUNITY_appRepositories AppServiceProvider.php, App]]
- 1 edge to [[_COMMUNITY_appHttpControllers ProductController.php,]]
- 1 edge to [[_COMMUNITY_appRepositories Ingredient, .casts()]]

## Top bridge nodes
- [[.index()_2]] - degree 5, connects to 4 communities
- [[CategoryRepository]] - degree 11, connects to 3 communities
- [[CatalogController]] - degree 6, connects to 2 communities
- [[.__construct()]] - degree 3, connects to 1 community
- [[.__construct()_1]] - degree 2, connects to 1 community