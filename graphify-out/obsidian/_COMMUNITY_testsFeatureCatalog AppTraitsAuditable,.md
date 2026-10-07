---
type: community
cohesion: 0.14
members: 29
---

# tests/Feature/Catalog: App\Traits\Auditable, 

**Cohesion:** 0.14 - loosely connected
**Members:** 29 nodes

## Members
- [[.casts()_7]] - code - backend/app/Models/Product.php
- [[.casts()_9]] - code - backend/app/Models/Recipe.php
- [[.excludedOptions()]] - code - backend/app/Models/Product.php
- [[.optionGroups()]] - code - backend/app/Models/Product.php
- [[.options()]] - code - backend/app/Models/OptionGroup.php
- [[.products()_1]] - code - backend/app/Models/OptionGroup.php
- [[.run()]] - code - backend/database/seeders/DatabaseSeeder.php
- [[.setUp()]] - code - backend/tests/Feature/AdminSaleManagementTest.php
- [[.setUp()_5]] - code - backend/tests/Feature/KdsApiTest.php
- [[.test_can_attach_products_to_option_group()]] - code - backend/tests/Feature/Catalog/OptionGroupTest.php
- [[.test_can_create_product_with_image_upload()]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[.test_can_create_product_with_option_groups()]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[.test_can_update_product_and_sync_option_groups()]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[.test_can_update_product_with_new_image_and_replaces_old_one()]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[.test_cannot_create_product_without_required_fields()]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[.test_cannot_delete_category_that_contains_products()]] - code - backend/tests/Feature/Catalog/CategoryTest.php
- [[.test_catalog_does_not_return_products_from_deleted_categories()]] - code - backend/tests/Feature/Catalog/CategoryTest.php
- [[.test_invalid_tag_is_rejected()]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[.test_it_syncs_offline_sales_and_deducts_inventory_and_is_idempotent()]] - code - backend/tests/Feature/SaleSyncTest.php
- [[.test_soft_delete_option_group_preserves_product_pivot_data()]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[.test_soft_delete_product_is_not_in_active_list()]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[.test_tag_field_is_validated_and_persisted()]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[.test_toggle_product_inactive_sets_reactivate_at()]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[AppTraitsAuditable]] - code
- [[Category]] - code - backend/app/Models/Category.php
- [[OptionGroup]] - code - backend/app/Models/OptionGroup.php
- [[Product]] - code - backend/app/Models/Product.php
- [[ProductTest]] - code - backend/tests/Feature/Catalog/ProductTest.php
- [[Recipe]] - code - backend/app/Models/Recipe.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/tests/Feature/Catalog_AppTraitsAuditable_
SORT file.name ASC
```

## Connections to other communities
- 20 edges to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 18 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 11 edges to [[_COMMUNITY_appModels Category.php, .scopeOrdered()]]
- 8 edges to [[_COMMUNITY_appHttpControllers OptionGroupController.p]]
- 6 edges to [[_COMMUNITY_appRepositories ProductRepository, .create(]]
- 5 edges to [[_COMMUNITY_appModels .movements(), .children()]]
- 5 edges to [[_COMMUNITY_testsFeature Customer.php, KdsApiTest.php]]
- 4 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 4 edges to [[_COMMUNITY_appRepositories AppServiceProvider.php, App]]
- 4 edges to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 3 edges to [[_COMMUNITY_appHttpControllers CatalogController, .__c]]
- 3 edges to [[_COMMUNITY_appServices InventoryMovementController, ._]]
- 2 edges to [[_COMMUNITY_appHttpControllers CategoryController.php,]]
- 2 edges to [[_COMMUNITY_appHttpControllers CashRegisterSessionCont]]
- 2 edges to [[_COMMUNITY_appHttpControllers OptionController.php, O]]
- 2 edges to [[_COMMUNITY_testsFeature AdminSaleManagementTest, .test]]
- 1 edge to [[_COMMUNITY_appConsoleCommands ReactivateProducts.php,]]
- 1 edge to [[_COMMUNITY_appHttpControllers CustomerController.php,]]
- 1 edge to [[_COMMUNITY_appHttpControllers IngredientCategoryContr]]
- 1 edge to [[_COMMUNITY_appHttpControllers LoyaltyConfigController]]
- 1 edge to [[_COMMUNITY_appHttpControllers OptionRecipeController.]]
- 1 edge to [[_COMMUNITY_appRepositories .paginated(), UserRepositor]]
- 1 edge to [[_COMMUNITY_appModels KdsController.php, KdsController]]

## Top bridge nodes
- [[AppTraitsAuditable]] - degree 22, connects to 12 communities
- [[Product]] - degree 43, connects to 11 communities
- [[Category]] - degree 33, connects to 7 communities
- [[OptionGroup]] - degree 23, connects to 5 communities
- [[Recipe]] - degree 16, connects to 4 communities