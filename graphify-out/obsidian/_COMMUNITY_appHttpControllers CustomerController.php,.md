---
type: community
cohesion: 0.15
members: 22
---

# app/Http/Controllers: CustomerController.php,

**Cohesion:** 0.15 - loosely connected
**Members:** 22 nodes

## Members
- [[.authorize()_2]] - code - backend/app/Http/Requests/CustomerRequest.php
- [[.casts()_2]] - code - backend/app/Models/Customer.php
- [[.destroy()_1]] - code - backend/app/Http/Controllers/CustomerController.php
- [[.find()]] - code - backend/app/Repositories/CustomerRepository.php
- [[.incrementPoints()]] - code - backend/app/Repositories/CustomerRepository.php
- [[.index()_4]] - code - backend/app/Http/Controllers/CustomerController.php
- [[.isVip()]] - code - backend/app/Models/Customer.php
- [[.messages()_2]] - code - backend/app/Http/Requests/CustomerRequest.php
- [[.rules()_2]] - code - backend/app/Http/Requests/CustomerRequest.php
- [[.sales()]] - code - backend/app/Models/Customer.php
- [[.show()_2]] - code - backend/app/Http/Controllers/CustomerController.php
- [[.store()_2]] - code - backend/app/Http/Controllers/CustomerController.php
- [[.toArray()_1]] - code - backend/app/Http/Resources/CustomerResource.php
- [[.update()_1]] - code - backend/app/Http/Controllers/CustomerController.php
- [[Customer]] - code - backend/app/Models/Customer.php
- [[CustomerController]] - code - backend/app/Http/Controllers/CustomerController.php
- [[CustomerController.php]] - code - backend/app/Http/Controllers/CustomerController.php
- [[CustomerRepository]] - code - backend/app/Repositories/CustomerRepository.php
- [[CustomerRepository.php]] - code - backend/app/Repositories/CustomerRepository.php
- [[CustomerRequest]] - code - backend/app/Http/Requests/CustomerRequest.php
- [[CustomerRequest.php]] - code - backend/app/Http/Requests/CustomerRequest.php
- [[CustomerResource]] - code - backend/app/Http/Resources/CustomerResource.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Http/Controllers_CustomerControllerphp
SORT file.name ASC
```

## Connections to other communities
- 3 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 2 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 2 edges to [[_COMMUNITY_appHttpControllers IngredientController.ph]]
- 2 edges to [[_COMMUNITY_appHttpControllers ProductController.php,]]
- 2 edges to [[_COMMUNITY_appHttpRequests AdminSaleRequest.php, Admi]]
- 2 edges to [[_COMMUNITY_appHttpResources CategoryResource.php, Cus]]
- 2 edges to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 1 edge to [[_COMMUNITY_appHttpControllers AuditLogController.php,]]
- 1 edge to [[_COMMUNITY_testsFeature Customer.php, KdsApiTest.php]]
- 1 edge to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 1 edge to [[_COMMUNITY_appModels Category.php, .scopeOrdered()]]
- 1 edge to [[_COMMUNITY_appModels .movements(), .children()]]
- 1 edge to [[_COMMUNITY_appRepositories AppServiceProvider.php, App]]

## Top bridge nodes
- [[Customer]] - degree 17, connects to 4 communities
- [[CustomerController.php]] - degree 7, connects to 3 communities
- [[CustomerRepository]] - degree 7, connects to 3 communities
- [[CustomerRequest]] - degree 8, connects to 1 community
- [[CustomerResource]] - degree 8, connects to 1 community