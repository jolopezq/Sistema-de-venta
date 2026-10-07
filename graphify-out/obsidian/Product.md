---
source_file: "backend/app/Models/Product.php"
type: "code"
community: "tests/Feature/Catalog: App\\Traits\\Auditable, "
location: "L17"
tags:
  - graphify/code
  - graphify/EXTRACTED
  - community/tests/Feature/Catalog_AppTraitsAuditable_
---

# Product

## Connections
- [[.allActive()]] - `calls` [EXTRACTED]
- [[.casts()_7]] - `method` [EXTRACTED]
- [[.category()_1]] - `method` [EXTRACTED]
- [[.create()_3]] - `references` [EXTRACTED]
- [[.deductFromRecipe()]] - `references` [EXTRACTED]
- [[.delete()_3]] - `references` [EXTRACTED]
- [[.excludedOptions()]] - `method` [EXTRACTED]
- [[.findWithRecipes()]] - `references` [EXTRACTED]
- [[.handle()]] - `calls` [INFERRED]
- [[.optionGroups()]] - `method` [EXTRACTED]
- [[.paginated()_1]] - `calls` [EXTRACTED]
- [[.recipes()_2]] - `method` [EXTRACTED]
- [[.run()]] - `calls` [INFERRED]
- [[.saleItems()]] - `method` [EXTRACTED]
- [[.scopeActive()]] - `method` [EXTRACTED]
- [[.setUp()]] - `calls` [EXTRACTED]
- [[.setUp()_5]] - `calls` [EXTRACTED]
- [[.syncExcludedOptions()]] - `references` [EXTRACTED]
- [[.syncOptionGroups()]] - `references` [EXTRACTED]
- [[.test_can_attach_products_to_option_group()]] - `calls` [INFERRED]
- [[.test_can_create_product_with_image_upload()]] - `calls` [INFERRED]
- [[.test_can_create_product_with_option_groups()]] - `calls` [INFERRED]
- [[.test_can_update_product_and_sync_option_groups()]] - `calls` [INFERRED]
- [[.test_can_update_product_with_new_image_and_replaces_old_one()]] - `calls` [INFERRED]
- [[.test_cannot_delete_category_that_contains_products()]] - `calls` [INFERRED]
- [[.test_catalog_does_not_return_products_from_deleted_categories()]] - `calls` [INFERRED]
- [[.test_it_syncs_offline_sales_and_deducts_inventory_and_is_idempotent()]] - `calls` [INFERRED]
- [[.test_soft_delete_option_group_preserves_product_pivot_data()]] - `calls` [INFERRED]
- [[.test_soft_delete_product_is_not_in_active_list()]] - `calls` [INFERRED]
- [[.test_toggle_product_inactive_sets_reactivate_at()]] - `calls` [INFERRED]
- [[.update()_13]] - `references` [EXTRACTED]
- [[.variants()]] - `method` [EXTRACTED]
- [[AdminSaleManagementTest]] - `references` [EXTRACTED]
- [[AdminSaleManagementTest.php]] - `imports` [EXTRACTED]
- [[AppTraitsAuditable]] - `mixes_in` [EXTRACTED]
- [[IlluminateDatabaseEloquentFactoriesHasFactory]] - `mixes_in` [EXTRACTED]
- [[IlluminateDatabaseEloquentModel]] - `inherits` [EXTRACTED]
- [[IlluminateDatabaseEloquentSoftDeletes]] - `mixes_in` [EXTRACTED]
- [[InventoryService.php]] - `imports` [EXTRACTED]
- [[KdsApiTest]] - `references` [EXTRACTED]
- [[KdsApiTest.php]] - `imports` [EXTRACTED]
- [[Product.php]] - `contains` [EXTRACTED]
- [[ProductRepository.php]] - `imports` [EXTRACTED]

#graphify/code #graphify/EXTRACTED #community/tests/Feature/Catalog_AppTraitsAuditable_