---
type: community
cohesion: 0.15
members: 14
---

# components: SaleDetailDrawer.vue, authStore

**Cohesion:** 0.15 - loosely connected
**Members:** 14 nodes

## Members
- [[SaleDetailDrawer.vue]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[authStore_1]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[emit_22]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[formatDate()_3]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[getPaymentLabel()_1]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[handleConfirmVoid()]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[isAdmin]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[isVoiding]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[sale_1]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[showVoidModal]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[turnoStore_3]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[useTurnoStore]] - code - frontend/src/stores/turno.js
- [[voidError]] - code - frontend/src/components/SaleDetailDrawer.vue
- [[voidReason]] - code - frontend/src/components/SaleDetailDrawer.vue

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/components_SaleDetailDrawervue_authStore
SORT file.name ASC
```

## Connections to other communities
- 2 edges to [[_COMMUNITY_components KitchenTicketModal.vue, allergenT]]
- 2 edges to [[_COMMUNITY_stores database.js, db]]
- 2 edges to [[_COMMUNITY_views RetroactiveSales.vue, activeTab]]
- 2 edges to [[_COMMUNITY_views Turno.vue, applyCustomDateRange()]]
- 1 edge to [[_COMMUNITY_components AdminSaleEditor.vue, addPayment()]]
- 1 edge to [[_COMMUNITY_components CashCloseWizard.vue, authStore]]
- 1 edge to [[_COMMUNITY_components CashExpenseModal.vue, canSubmit]]

## Top bridge nodes
- [[useTurnoStore]] - degree 7, connects to 6 communities
- [[SaleDetailDrawer.vue]] - degree 18, connects to 4 communities