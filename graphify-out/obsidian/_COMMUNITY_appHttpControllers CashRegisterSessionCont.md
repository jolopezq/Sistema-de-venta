---
type: community
cohesion: 0.15
members: 26
---

# app/Http/Controllers: CashRegisterSessionCont

**Cohesion:** 0.15 - loosely connected
**Members:** 26 nodes

## Members
- [[.active()]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[.buildReportPayload()]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[.calculateSessionStats()]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[.cashier()]] - code - backend/app/Models/CashRegisterSession.php
- [[.casts()_1]] - code - backend/app/Models/CashRegisterSession.php
- [[.casts()_12]] - code - backend/app/Models/SalePayment.php
- [[.close()]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[.index()_1]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[.isOpen()]] - code - backend/app/Models/CashRegisterSession.php
- [[.movements()]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[.recordMovement()]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[.report()]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[.sale()_3]] - code - backend/app/Models/SalePayment.php
- [[.show()]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[.store()]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[.test_cash_expense_reduces_expected_cash_but_qr_expense_does_not()]] - code - backend/tests/Feature/CashRegisterSessionTest.php
- [[.test_cashier_can_close_session_with_arqueo_and_breakdown()]] - code - backend/tests/Feature/CashRegisterSessionTest.php
- [[.test_cashier_can_get_active_session_with_realtime_stats()]] - code - backend/tests/Feature/CashRegisterSessionTest.php
- [[.test_cashier_can_open_cash_register_session()]] - code - backend/tests/Feature/CashRegisterSessionTest.php
- [[.test_cashier_cannot_open_duplicate_active_session()]] - code - backend/tests/Feature/CashRegisterSessionTest.php
- [[.test_sales_index_can_filter_by_date_and_payment_method()]] - code - backend/tests/Feature/CashRegisterSessionTest.php
- [[CashRegisterSession]] - code - backend/app/Models/CashRegisterSession.php
- [[CashRegisterSessionController]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[CashRegisterSessionController.php]] - code - backend/app/Http/Controllers/CashRegisterSessionController.php
- [[CashRegisterSessionTest]] - code - backend/tests/Feature/CashRegisterSessionTest.php
- [[SalePayment]] - code - backend/app/Models/SalePayment.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Http/Controllers_CashRegisterSessionCont
SORT file.name ASC
```

## Connections to other communities
- 12 edges to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 12 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 7 edges to [[_COMMUNITY_testsFeature .toArray(), .parent()]]
- 6 edges to [[_COMMUNITY_appHttpControllers AuditLogController.php,]]
- 3 edges to [[_COMMUNITY_appModels KdsController.php, KdsController]]
- 3 edges to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 2 edges to [[_COMMUNITY_testsFeatureCatalog AppTraitsAuditable,]]
- 1 edge to [[_COMMUNITY_appModels .movements(), .children()]]

## Top bridge nodes
- [[CashRegisterSession]] - degree 24, connects to 4 communities
- [[SalePayment]] - degree 13, connects to 4 communities
- [[CashRegisterSessionController.php]] - degree 8, connects to 4 communities
- [[.recordMovement()]] - degree 6, connects to 3 communities
- [[.calculateSessionStats()]] - degree 12, connects to 2 communities