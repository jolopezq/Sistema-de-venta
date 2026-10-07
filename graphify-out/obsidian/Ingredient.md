---
source_file: "backend/app/Models/Ingredient.php"
type: "code"
community: "app/Repositories: Ingredient, .casts()"
location: "L16"
tags:
  - graphify/code
  - graphify/EXTRACTED
  - community/app/Repositories_Ingredient_casts
---

# Ingredient

## Connections
- [[.adminUpdateSale()]] - `calls` [INFERRED]
- [[.all()_1]] - `calls` [EXTRACTED]
- [[.casts()_4]] - `method` [EXTRACTED]
- [[.category()]] - `method` [EXTRACTED]
- [[.create()_2]] - `references` [EXTRACTED]
- [[.delete()_2]] - `references` [EXTRACTED]
- [[.find()_1]] - `references` [EXTRACTED]
- [[.findWithMovements()]] - `references` [EXTRACTED]
- [[.index()_2]] - `calls` [INFERRED]
- [[.index()_7]] - `references` [EXTRACTED]
- [[.inventoryMovements()]] - `method` [EXTRACTED]
- [[.isBelowMinimumStock()]] - `method` [EXTRACTED]
- [[.paginated()]] - `calls` [EXTRACTED]
- [[.recipes()]] - `method` [EXTRACTED]
- [[.recordManualMovement()]] - `references` [EXTRACTED]
- [[.recordRestock()]] - `references` [EXTRACTED]
- [[.run()]] - `calls` [INFERRED]
- [[.setUp()]] - `calls` [EXTRACTED]
- [[.store()_5]] - `calls` [EXTRACTED]
- [[.test_it_syncs_offline_sales_and_deducts_inventory_and_is_idempotent()]] - `calls` [INFERRED]
- [[.update()_12]] - `references` [EXTRACTED]
- [[.voidSale()_1]] - `calls` [INFERRED]
- [[AdminSaleManagementTest]] - `references` [EXTRACTED]
- [[AdminSaleManagementTest.php]] - `imports` [EXTRACTED]
- [[AppTraitsAuditable]] - `mixes_in` [EXTRACTED]
- [[IlluminateDatabaseEloquentFactoriesHasFactory]] - `mixes_in` [EXTRACTED]
- [[IlluminateDatabaseEloquentModel]] - `inherits` [EXTRACTED]
- [[IlluminateDatabaseEloquentSoftDeletes]] - `mixes_in` [EXTRACTED]
- [[Ingredient.php]] - `contains` [EXTRACTED]
- [[IngredientRepository.php]] - `imports` [EXTRACTED]
- [[InventoryMovementController.php]] - `imports` [EXTRACTED]
- [[InventoryService.php]] - `imports` [EXTRACTED]

#graphify/code #graphify/EXTRACTED #community/app/Repositories_Ingredient_casts