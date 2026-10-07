---
type: community
cohesion: 0.12
members: 23
---

# app/Http/Controllers: UserController.php, Req

**Cohesion:** 0.12 - loosely connected
**Members:** 23 nodes

## Members
- [[.__construct()_8]] - code - backend/app/Http/Controllers/UserController.php
- [[.authorize()_13]] - code - backend/app/Http/Requests/UserRequest.php
- [[.create()_5]] - code - backend/app/Repositories/UserRepository.php
- [[.delete()_4]] - code - backend/app/Repositories/UserRepository.php
- [[.destroy()_9]] - code - backend/app/Http/Controllers/UserController.php
- [[.find()_2]] - code - backend/app/Repositories/UserRepository.php
- [[.index()_15]] - code - backend/app/Http/Controllers/UserController.php
- [[.messages()_11]] - code - backend/app/Http/Requests/UserRequest.php
- [[.resetPassword()]] - code - backend/app/Http/Controllers/UserController.php
- [[.rules()_13]] - code - backend/app/Http/Requests/UserRequest.php
- [[.show()_10]] - code - backend/app/Http/Controllers/UserController.php
- [[.store()_11]] - code - backend/app/Http/Controllers/UserController.php
- [[.toArray()_7]] - code - backend/app/Http/Resources/UserResource.php
- [[.update()_9]] - code - backend/app/Http/Controllers/UserController.php
- [[.update()_14]] - code - backend/app/Repositories/UserRepository.php
- [[Request_1]] - code
- [[UserController]] - code - backend/app/Http/Controllers/UserController.php
- [[UserController.php]] - code - backend/app/Http/Controllers/UserController.php
- [[UserRepository]] - code - backend/app/Repositories/UserRepository.php
- [[UserRequest]] - code - backend/app/Http/Requests/UserRequest.php
- [[UserRequest.php]] - code - backend/app/Http/Requests/UserRequest.php
- [[UserResource]] - code - backend/app/Http/Resources/UserResource.php
- [[UserResource.php]] - code - backend/app/Http/Resources/UserResource.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Http/Controllers_UserControllerphp_Req
SORT file.name ASC
```

## Connections to other communities
- 4 edges to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 3 edges to [[_COMMUNITY_appHttpControllers ProductController.php,]]
- 2 edges to [[_COMMUNITY_appHttpControllers IngredientController.ph]]
- 2 edges to [[_COMMUNITY_appHttpRequests AdminSaleRequest.php, Admi]]
- 2 edges to [[_COMMUNITY_appHttpControllers AuditLogController.php,]]
- 2 edges to [[_COMMUNITY_appHttpResources CategoryResource.php, Cus]]
- 2 edges to [[_COMMUNITY_appRepositories .paginated(), UserRepositor]]
- 1 edge to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]

## Top bridge nodes
- [[UserController.php]] - degree 6, connects to 2 communities
- [[UserResource.php]] - degree 3, connects to 2 communities
- [[UserController]] - degree 10, connects to 1 community
- [[UserRepository]] - degree 9, connects to 1 community
- [[UserRequest]] - degree 8, connects to 1 community