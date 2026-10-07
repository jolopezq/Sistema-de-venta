---
source_file: "backend/app/Models/Category.php"
type: "code"
community: "tests/Feature/Catalog: App\\Traits\\Auditable, "
location: "L16"
tags:
  - graphify/code
  - graphify/INFERRED
  - community/tests/Feature/Catalog_AppTraitsAuditable_
---

# Category

## Connections
- [[.allOrdered()]] - `calls` [EXTRACTED]
- [[.children()]] - `references_constant` [EXTRACTED]
- [[.create()]] - `references` [EXTRACTED]
- [[.delete()]] - `references` [EXTRACTED]
- [[.destroy()]] - `references` [EXTRACTED]
- [[.parent()]] - `references_constant` [EXTRACTED]
- [[.products()]] - `method` [EXTRACTED]
- [[.run()]] - `calls` [INFERRED]
- [[.scopeOrdered()]] - `method` [EXTRACTED]
- [[.setUp()]] - `calls` [INFERRED]
- [[.setUp()_5]] - `calls` [INFERRED]
- [[.test_can_attach_products_to_option_group()]] - `calls` [INFERRED]
- [[.test_can_create_product_with_image_upload()]] - `calls` [INFERRED]
- [[.test_can_create_product_with_option_groups()]] - `calls` [INFERRED]
- [[.test_can_delete_empty_category()]] - `calls` [INFERRED]
- [[.test_can_update_product_and_sync_option_groups()]] - `calls` [INFERRED]
- [[.test_can_update_product_with_new_image_and_replaces_old_one()]] - `calls` [INFERRED]
- [[.test_cannot_delete_category_that_contains_products()]] - `calls` [INFERRED]
- [[.test_catalog_does_not_return_products_from_deleted_categories()]] - `calls` [INFERRED]
- [[.test_invalid_tag_is_rejected()]] - `calls` [INFERRED]
- [[.test_it_syncs_offline_sales_and_deducts_inventory_and_is_idempotent()]] - `calls` [INFERRED]
- [[.test_soft_delete_option_group_preserves_product_pivot_data()]] - `calls` [INFERRED]
- [[.test_soft_delete_product_is_not_in_active_list()]] - `calls` [INFERRED]
- [[.test_tag_field_is_validated_and_persisted()]] - `calls` [INFERRED]
- [[.test_toggle_product_inactive_sets_reactivate_at()]] - `calls` [INFERRED]
- [[.update()]] - `references` [EXTRACTED]
- [[.update()_10]] - `references` [EXTRACTED]
- [[AppTraitsAuditable]] - `mixes_in` [EXTRACTED]
- [[Category.php]] - `contains` [EXTRACTED]
- [[CategoryRepository.php]] - `imports` [EXTRACTED]
- [[IlluminateDatabaseEloquentFactoriesHasFactory]] - `mixes_in` [EXTRACTED]
- [[IlluminateDatabaseEloquentModel]] - `inherits` [EXTRACTED]
- [[IlluminateDatabaseEloquentSoftDeletes]] - `mixes_in` [EXTRACTED]

#graphify/code #graphify/INFERRED #community/tests/Feature/Catalog_AppTraitsAuditable_