---
type: community
cohesion: 0.20
members: 15
---

# stores: database.js, db

**Cohesion:** 0.20 - loosely connected
**Members:** 15 nodes

## Members
- [[..storesorderQueue.js]] - code - frontend/src/stores/orderQueue.js
- [[buildCartKey()]] - code - frontend/src/stores/cart.js
- [[cart.js]] - code - frontend/src/stores/cart.js
- [[catalog.js]] - code - frontend/src/stores/catalog.js
- [[database.js]] - code - frontend/src/db/database.js
- [[db]] - code - frontend/src/db/database.js
- [[formatLocalDate()]] - code - frontend/src/stores/turno.js
- [[generateOrderNumber()]] - code - frontend/src/stores/cart.js
- [[generateUUID()]] - code - frontend/src/stores/cart.js
- [[pruneOldSyncedSales()]] - code - frontend/src/services/syncService.js
- [[syncPendingSales()]] - code - frontend/src/services/syncService.js
- [[syncService.js]] - code - frontend/src/services/syncService.js
- [[turno.js]] - code - frontend/src/stores/turno.js
- [[useCartStore]] - code - frontend/src/stores/cart.js
- [[useOrderQueueStore]] - code - frontend/src/stores/orderQueue.js

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/stores_databasejs_db
SORT file.name ASC
```

## Connections to other communities
- 6 edges to [[_COMMUNITY_components AuditLogDetailModal.vue, emit]]
- 5 edges to [[_COMMUNITY_views apiFetch(), alertAction()]]
- 4 edges to [[_COMMUNITY_views CartItem.vue, emit]]
- 3 edges to [[_COMMUNITY_src App.vue, auth]]
- 2 edges to [[_COMMUNITY_components AdminSaleEditor.vue, addPayment()]]
- 2 edges to [[_COMMUNITY_components SaleDetailDrawer.vue, authStore]]
- 2 edges to [[_COMMUNITY_views main.js, app]]
- 2 edges to [[_COMMUNITY_views Delivery.vue, allergenMap]]
- 1 edge to [[_COMMUNITY_components CashCloseWizard.vue, authStore]]
- 1 edge to [[_COMMUNITY_components CashExpenseModal.vue, canSubmit]]
- 1 edge to [[_COMMUNITY_components ModifierModal.vue, catalog]]
- 1 edge to [[_COMMUNITY_views Menu.vue, activeCategory]]
- 1 edge to [[_COMMUNITY_views RetroactiveSales.vue, activeTab]]
- 1 edge to [[_COMMUNITY_views Turno.vue, applyCustomDateRange()]]

## Top bridge nodes
- [[turno.js]] - degree 12, connects to 8 communities
- [[catalog.js]] - degree 11, connects to 7 communities
- [[..storesorderQueue.js]] - degree 8, connects to 5 communities
- [[database.js]] - degree 8, connects to 2 communities
- [[db]] - degree 8, connects to 2 communities