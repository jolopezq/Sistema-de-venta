---
type: community
cohesion: 0.25
members: 11
---

# app/Http/Controllers: SystemBackupController.

**Cohesion:** 0.25 - loosely connected
**Members:** 11 nodes

## Members
- [[.__construct()_7]] - code - backend/app/Http/Controllers/SystemBackupController.php
- [[.createCompressedSqliteBackup()]] - code - backend/app/Services/BackupService.php
- [[.download()]] - code - backend/app/Http/Controllers/SystemBackupController.php
- [[.gzipFile()]] - code - backend/app/Services/BackupService.php
- [[.restore()]] - code - backend/app/Http/Controllers/SystemBackupController.php
- [[.restoreCompressedSqliteBackup()]] - code - backend/app/Services/BackupService.php
- [[BackupService]] - code - backend/app/Services/BackupService.php
- [[BackupService.php]] - code - backend/app/Services/BackupService.php
- [[SymfonyComponentHttpFoundationBinaryFileResponse]] - code
- [[SystemBackupController]] - code - backend/app/Http/Controllers/SystemBackupController.php
- [[SystemBackupController.php]] - code - backend/app/Http/Controllers/SystemBackupController.php

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/app/Http/Controllers_SystemBackupController
SORT file.name ASC
```

## Connections to other communities
- 4 edges to [[_COMMUNITY_appHttpControllers AuthController.php, Aut]]
- 3 edges to [[_COMMUNITY_appHttpControllers AuditLogController.php,]]
- 2 edges to [[_COMMUNITY_appServices .__construct(), .handle()]]
- 1 edge to [[_COMMUNITY_appModels AuditLog.php, CashMovement.php]]

## Top bridge nodes
- [[SystemBackupController.php]] - degree 6, connects to 3 communities
- [[.download()]] - degree 5, connects to 3 communities
- [[.restore()]] - degree 4, connects to 3 communities
- [[SystemBackupController]] - degree 6, connects to 1 community