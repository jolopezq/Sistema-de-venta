---
type: community
cohesion: 0.09
members: 46
---

# app/Models: AuditLog.php, CashMovement.php

**Cohesion:** 0.09 - loosely connected
**Members:** 46 nodes

## Members
- [[.casts()]] - code - backend/app/Models/CashMovement.php
- [[.casts()_3]] - code - backend/app/Models/DeliveryOrder.php
- [[.casts()_8]] - code - backend/app/Models/ProductVariant.php
- [[.casts()_11]] - code - backend/app/Models/SaleItem.php
- [[.category()_1]] - code - backend/app/Models/Product.php
- [[.ingredient()_2]] - code - backend/app/Models/Recipe.php
- [[.isExpense()]] - code - backend/app/Models/CashMovement.php
- [[.isExtraIncome()]] - code - backend/app/Models/CashMovement.php
- [[.option()_1]] - code - backend/app/Models/SaleItemOption.php
- [[.optionGroup()_1]] - code - backend/app/Models/SaleItemOption.php
- [[.product()]] - code - backend/app/Models/ProductVariant.php
- [[.product()_1]] - code - backend/app/Models/Recipe.php
- [[.product()_2]] - code - backend/app/Models/SaleItem.php
- [[.sale()]] - code - backend/app/Models/CashMovement.php
- [[.sale()_1]] - code - backend/app/Models/DeliveryOrder.php
- [[.sale()_2]] - code - backend/app/Models/SaleItem.php
- [[.saleItem()]] - code - backend/app/Models/SaleItemOption.php
- [[.saleItemOptions()]] - code - backend/app/Models/SaleItem.php
- [[.session()]] - code - backend/app/Models/CashMovement.php
- [[.user()_1]] - code - backend/app/Models/CashMovement.php
- [[AuditLog.php]] - code - backend/app/Models/AuditLog.php
- [[CashMovement]] - code - backend/app/Models/CashMovement.php
- [[CashMovement.php]] - code - backend/app/Models/CashMovement.php
- [[CashRegisterSession.php]] - code - backend/app/Models/CashRegisterSession.php
- [[DeliveryOrder]] - code - backend/app/Models/DeliveryOrder.php
- [[DeliveryOrder.php]] - code - backend/app/Models/DeliveryOrder.php
- [[IlluminateDatabaseEloquentFactoriesHasFactory]] - code
- [[IlluminateDatabaseEloquentModel]] - code
- [[IlluminateDatabaseEloquentRelationsBelongsTo]] - code
- [[Ingredient.php]] - code - backend/app/Models/Ingredient.php
- [[IngredientCategory.php]] - code - backend/app/Models/IngredientCategory.php
- [[InventoryMovement.php]] - code - backend/app/Models/InventoryMovement.php
- [[LoyaltyConfig.php]] - code - backend/app/Models/LoyaltyConfig.php
- [[Option.php]] - code - backend/app/Models/Option.php
- [[OptionRecipe.php]] - code - backend/app/Models/OptionRecipe.php
- [[PlatformCatalogMapping]] - code - backend/app/Models/PlatformCatalogMapping.php
- [[PlatformCatalogMapping.php]] - code - backend/app/Models/PlatformCatalogMapping.php
- [[ProductVariant]] - code - backend/app/Models/ProductVariant.php
- [[ProductVariant.php]] - code - backend/app/Models/ProductVariant.php
- [[Recipe.php]] - code - backend/app/Models/Recipe.php
- [[SaleItem]] - code - backend/app/Models/SaleItem.php
- [[SaleItem.php]] - code - backend/app/Models/SaleItem.php
- [[SaleItemOption]] - code - backend/app/Models/SaleItemOption.php
- [[SaleItemOption.php]] - code - backend/app/Models/SaleItemOption.php
- [[SalePayment.php]] - code - backend/app/Models/SalePayment.php
- [[SaleSyncService.php]] - code - backend/app/Services/SaleSyncService.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Models_AuditLogphp_CashMovementphp
SORT file.name ASC
```

## Connections to other communities
- 18 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 16 edges to [[_COMMUNITY_appModels Category.php, .scopeOrdered()]]
- 12 edges to [[_COMMUNITY_appHttpControllers CashRegisterSessionCont]]
- 9 edges to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 8 edges to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 8 edges to [[_COMMUNITY_appServices InventoryMovementController, ._]]
- 7 edges to [[_COMMUNITY_appModels KdsController.php, KdsController]]
- 6 edges to [[_COMMUNITY_testsFeature Customer.php, KdsApiTest.php]]
- 5 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 4 edges to [[_COMMUNITY_appHttpControllers LoyaltyConfigController]]
- 4 edges to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 3 edges to [[_COMMUNITY_appModels .movements(), .children()]]
- 3 edges to [[_COMMUNITY_appHttpControllers CustomerController.php,]]
- 3 edges to [[_COMMUNITY_appHttpControllers IngredientCategoryContr]]
- 3 edges to [[_COMMUNITY_appHttpControllers OptionController.php, O]]
- 3 edges to [[_COMMUNITY_appHttpControllers OptionRecipeController.]]
- 1 edge to [[_COMMUNITY_appHttpControllers AuditLogController.php,]]
- 1 edge to [[_COMMUNITY_appHttpControllers IngredientController.ph]]
- 1 edge to [[_COMMUNITY_appHttpControllers SystemBackupController.]]
- 1 edge to [[_COMMUNITY_databasefactories .active(), Auditable.php]]
- 1 edge to [[_COMMUNITY_appRepositories ProductRepository, .create(]]

## Top bridge nodes
- [[IlluminateDatabaseEloquentModel]] - degree 44, connects to 15 communities
- [[IlluminateDatabaseEloquentFactoriesHasFactory]] - degree 38, connects to 12 communities
- [[IlluminateDatabaseEloquentRelationsBelongsTo]] - degree 36, connects to 6 communities
- [[Ingredient.php]] - degree 11, connects to 6 communities
- [[AuditLog.php]] - degree 9, connects to 5 communities