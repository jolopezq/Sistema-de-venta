---
type: community
cohesion: 0.21
members: 17
---

# app/Models: Category.php, .scopeOrdered()

**Cohesion:** 0.21 - loosely connected
**Members:** 17 nodes

## Members
- [[.scopeActive()]] - code - backend/app/Models/Product.php
- [[.scopeOrdered()]] - code - backend/app/Models/Category.php
- [[Category.php]] - code - backend/app/Models/Category.php
- [[CategoryTest.php]] - code - backend/tests/Feature/Catalog/CategoryTest.php
- [[DatabaseSeeder]] - code - backend/database/seeders/DatabaseSeeder.php
- [[DatabaseSeeder.php]] - code - backend/database/seeders/DatabaseSeeder.php
- [[IlluminateDatabaseEloquentBuilder]] - code
- [[IlluminateDatabaseEloquentSoftDeletes]] - code
- [[IlluminateDatabaseSeeder]] - code
- [[IlluminateNotificationsNotifiable]] - code
- [[LaravelSanctumHasApiTokens]] - code
- [[OptionGroup.php]] - code - backend/app/Models/OptionGroup.php
- [[OptionGroupTest.php]] - code - backend/tests/Feature/Catalog/OptionGroupTest.php
- [[Product.php]] - code - backend/app/Models/Product.php
- [[ProductTest.php]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[User.php]] - code - backend/app/Models/User.php
- [[auth.php]] - code - backend/config/auth.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Models_Categoryphp_scopeOrdered
SORT file.name ASC
```

## Connections to other communities
- 20 edges to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 16 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 11 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 3 edges to [[_COMMUNITY_appModels .movements(), .children()]]
- 2 edges to [[_COMMUNITY_testsFeature Customer.php, KdsApiTest.php]]
- 1 edge to [[_COMMUNITY_appConsoleCommands ReactivateProducts.php,]]
- 1 edge to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 1 edge to [[_COMMUNITY_appHttpControllers CustomerController.php,]]
- 1 edge to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 1 edge to [[_COMMUNITY_appHttpControllers IngredientCategoryContr]]
- 1 edge to [[_COMMUNITY_appHttpControllers OptionController.php, O]]
- 1 edge to [[_COMMUNITY_appModels KdsController.php, KdsController]]
- 1 edge to [[_COMMUNITY_databasefactories .active(), Auditable.php]]

## Top bridge nodes
- [[IlluminateDatabaseEloquentSoftDeletes]] - degree 20, connects to 9 communities
- [[User.php]] - degree 19, connects to 5 communities
- [[Category.php]] - degree 14, connects to 5 communities
- [[Product.php]] - degree 13, connects to 5 communities
- [[ProductTest.php]] - degree 8, connects to 3 communities