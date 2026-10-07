---
type: community
cohesion: 0.50
members: 4
---

# app/Repositories: .paginated(), UserRepositor

**Cohesion:** 0.50 - moderately connected
**Members:** 4 nodes

## Members
- [[.paginated()_1]] - code - backend/app/Repositories/ProductRepository.php
- [[.paginated()_2]] - code - backend/app/Repositories/UserRepository.php
- [[IlluminateContractsPaginationLengthAwarePaginator]] - code
- [[UserRepository.php]] - code - backend/app/Repositories/UserRepository.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Repositories_paginated_UserRepositor
SORT file.name ASC
```

## Connections to other communities
- 2 edges to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 2 edges to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 2 edges to [[_COMMUNITY_appHttpControllers UserController.php, Req]]
- 1 edge to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 1 edge to [[_COMMUNITY_appRepositories AppServiceProvider.php, App]]
- 1 edge to [[_COMMUNITY_appRepositories ProductRepository, .create(]]

## Top bridge nodes
- [[IlluminateContractsPaginationLengthAwarePaginator]] - degree 6, connects to 2 communities
- [[.paginated()_1]] - degree 3, connects to 2 communities
- [[UserRepository.php]] - degree 3, connects to 2 communities
- [[.paginated()_2]] - degree 3, connects to 2 communities