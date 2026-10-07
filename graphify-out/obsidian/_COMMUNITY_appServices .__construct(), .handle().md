---
type: community
cohesion: 0.19
members: 18
---

# app/Services: .__construct(), .handle()

**Cohesion:** 0.19 - loosely connected
**Members:** 18 nodes

## Members
- [[.__construct()_6]] - code - backend/app/Http/Controllers/SaleController.php
- [[.__construct()_10]] - code - backend/app/Services/SaleSyncService.php
- [[.adminCreateSale()]] - code - backend/app/Services/SaleSyncService.php
- [[.adminUpdateSale()]] - code - backend/app/Services/SaleSyncService.php
- [[.create()_4]] - code - backend/app/Repositories/SaleRepository.php
- [[.existsByUuid()]] - code - backend/app/Repositories/SaleRepository.php
- [[.handle()_3]] - code - backend/app/Jobs/ProcessOfflineSaleJob.php
- [[.insertSaleItems()]] - code - backend/app/Services/SaleSyncService.php
- [[.log()]] - code - backend/app/Services/AuditLogger.php
- [[.processSale()]] - code - backend/app/Services/SaleSyncService.php
- [[.syncBatch()]] - code - backend/app/Services/SaleSyncService.php
- [[.updateLoyaltyPoints()]] - code - backend/app/Services/SaleSyncService.php
- [[.user()]] - code - backend/app/Models/AuditLog.php
- [[AuditLog]] - code - backend/app/Models/AuditLog.php
- [[AuditLogger]] - code - backend/app/Services/AuditLogger.php
- [[AuditLogger.php]] - code - backend/app/Services/AuditLogger.php
- [[SaleRepository]] - code - backend/app/Repositories/SaleRepository.php
- [[SaleSyncService]] - code - backend/app/Services/SaleSyncService.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Services___construct_handle
SORT file.name ASC
```

## Connections to other communities
- 8 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 7 edges to [[_COMMUNITY_appModels KdsController.php, KdsController]]
- 3 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 3 edges to [[_COMMUNITY_appJobs ProcessOfflineSaleJob.php, ProcessO]]
- 3 edges to [[_COMMUNITY_appServices InventoryMovementController, ._]]
- 3 edges to [[_COMMUNITY_appHttpControllers LoyaltyConfigController]]
- 3 edges to [[_COMMUNITY_appHttpControllers CashRegisterSessionCont]]
- 2 edges to [[_COMMUNITY_appHttpControllers SystemBackupController.]]
- 2 edges to [[_COMMUNITY_appHttpControllers CustomerController.php,]]
- 2 edges to [[_COMMUNITY_appRepositories ProductRepository, .create(]]
- 1 edge to [[_COMMUNITY_appHttpControllers AuditLogController.php,]]
- 1 edge to [[_COMMUNITY_databasefactories .active(), Auditable.php]]
- 1 edge to [[_COMMUNITY_appRepositories Ingredient, .casts()]]
- 1 edge to [[_COMMUNITY_appHttpControllers OptionController.php, O]]
- 1 edge to [[_COMMUNITY_appRepositories AppServiceProvider.php, App]]

## Top bridge nodes
- [[SaleSyncService]] - degree 18, connects to 7 communities
- [[.adminUpdateSale()]] - degree 10, connects to 6 communities
- [[AuditLog]] - degree 10, connects to 4 communities
- [[SaleRepository]] - degree 8, connects to 3 communities
- [[.insertSaleItems()]] - degree 8, connects to 3 communities