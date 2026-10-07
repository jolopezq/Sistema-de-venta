---
type: community
cohesion: 0.24
members: 11
---

# database/factories: .active(), Auditable.php

**Cohesion:** 0.24 - loosely connected
**Members:** 11 nodes

## Members
- [[.active()_1]] - code - backend/app/Models/LoyaltyConfig.php
- [[.definition()]] - code - backend/database/factories/UserFactory.php
- [[.unverified()]] - code - backend/database/factories/UserFactory.php
- [[Auditable.php]] - code - backend/app/Traits/Auditable.php
- [[IlluminateDatabaseEloquentFactoriesFactory]] - code
- [[UserFactory]] - code - backend/database/factories/UserFactory.php
- [[UserFactory.php]] - code - backend/database/factories/UserFactory.php
- [[bootAuditable()]] - code - backend/app/Traits/Auditable.php
- [[logAction()]] - code - backend/app/Traits/Auditable.php
- [[self]] - code
- [[static]] - code

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/database/factories_active_Auditablephp
SORT file.name ASC
```

## Connections to other communities
- 1 edge to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]
- 1 edge to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 1 edge to [[_COMMUNITY_appHttpControllers LoyaltyConfigController]]
- 1 edge to [[_COMMUNITY_appModels Category.php, .scopeOrdered()]]

## Top bridge nodes
- [[.active()_1]] - degree 3, connects to 1 community
- [[Auditable.php]] - degree 3, connects to 1 community
- [[UserFactory.php]] - degree 3, connects to 1 community
- [[logAction()]] - degree 2, connects to 1 community