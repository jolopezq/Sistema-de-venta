---
type: community
cohesion: 0.05
members: 48
---

# components: AuditLogDetailModal.vue, emit

**Cohesion:** 0.05 - loosely connected
**Members:** 48 nodes

## Members
- [[API_URL]] - code - frontend/src/services/api.js
- [[AuditLogDetailModal.vue]] - code - frontend/src/components/AuditLogDetailModal.vue
- [[AuditLogs.vue]] - code - frontend/src/views/AuditLogs.vue
- [[DownloadBackupModal.vue]] - code - frontend/src/components/DownloadBackupModal.vue
- [[RestoreBackupModal.vue]] - code - frontend/src/components/RestoreBackupModal.vue
- [[api.js]] - code - frontend/src/services/api.js
- [[apiDownload()]] - code - frontend/src/services/api.js
- [[applyFilters()]] - code - frontend/src/views/AuditLogs.vue
- [[changePage()]] - code - frontend/src/views/AuditLogs.vue
- [[clearFilters()]] - code - frontend/src/views/AuditLogs.vue
- [[emit_1]] - code - frontend/src/components/AuditLogDetailModal.vue
- [[emit_6]] - code - frontend/src/components/DownloadBackupModal.vue
- [[emit_21]] - code - frontend/src/components/RestoreBackupModal.vue
- [[errorMessage_2]] - code - frontend/src/components/DownloadBackupModal.vue
- [[errorMessage_3]] - code - frontend/src/components/RestoreBackupModal.vue
- [[exportCSV()]] - code - frontend/src/views/AuditLogs.vue
- [[fetchLogs()]] - code - frontend/src/views/AuditLogs.vue
- [[fetchUsers()_1]] - code - frontend/src/views/AuditLogs.vue
- [[filters]] - code - frontend/src/views/AuditLogs.vue
- [[formatDate()]] - code - frontend/src/components/AuditLogDetailModal.vue
- [[formatDate()_4]] - code - frontend/src/views/AuditLogs.vue
- [[getActionStyle()]] - code - frontend/src/views/AuditLogs.vue
- [[getInitials()_1]] - code - frontend/src/views/AuditLogs.vue
- [[getShortDescription()]] - code - frontend/src/views/AuditLogs.vue
- [[handleDownload()]] - code - frontend/src/components/DownloadBackupModal.vue
- [[handleRestore()]] - code - frontend/src/components/RestoreBackupModal.vue
- [[isDownloading]] - code - frontend/src/components/DownloadBackupModal.vue
- [[isRestoring]] - code - frontend/src/components/RestoreBackupModal.vue
- [[logs]] - code - frontend/src/views/AuditLogs.vue
- [[onClose()]] - code - frontend/src/components/DownloadBackupModal.vue
- [[onClose()_1]] - code - frontend/src/components/RestoreBackupModal.vue
- [[onFileChange()]] - code - frontend/src/components/RestoreBackupModal.vue
- [[pagination]] - code - frontend/src/views/AuditLogs.vue
- [[parsedChanges]] - code - frontend/src/components/AuditLogDetailModal.vue
- [[password]] - code - frontend/src/components/DownloadBackupModal.vue
- [[password_1]] - code - frontend/src/components/RestoreBackupModal.vue
- [[pingStatus]] - code - frontend/src/views/AuditLogs.vue
- [[pingWindows()]] - code - frontend/src/views/AuditLogs.vue
- [[props_1]] - code - frontend/src/components/AuditLogDetailModal.vue
- [[router_3]] - code - frontend/src/views/AuditLogs.vue
- [[selectedFile]] - code - frontend/src/components/RestoreBackupModal.vue
- [[selectedLog]] - code - frontend/src/views/AuditLogs.vue
- [[showBackupModal]] - code - frontend/src/views/AuditLogs.vue
- [[showRestoreModal]] - code - frontend/src/views/AuditLogs.vue
- [[successInfo]] - code - frontend/src/components/DownloadBackupModal.vue
- [[successMessage_1]] - code - frontend/src/components/RestoreBackupModal.vue
- [[users_1]] - code - frontend/src/views/AuditLogs.vue
- [[viewDetails()]] - code - frontend/src/views/AuditLogs.vue

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/components_AuditLogDetailModalvue_emit
SORT file.name ASC
```

## Connections to other communities
- 6 edges to [[_COMMUNITY_stores database.js, db]]
- 4 edges to [[_COMMUNITY_views apiFetch(), alertAction()]]
- 1 edge to [[_COMMUNITY_components AdminSaleEditor.vue, addPayment()]]
- 1 edge to [[_COMMUNITY_componentsMenu OptionGroupDetail.vue, delet]]
- 1 edge to [[_COMMUNITY_componentsMenu OptionGroupForm.vue, confirm]]
- 1 edge to [[_COMMUNITY_views main.js, app]]
- 1 edge to [[_COMMUNITY_components KitchenTicketModal.vue, allergenT]]
- 1 edge to [[_COMMUNITY_views AdminUsers.vue, activeTab]]
- 1 edge to [[_COMMUNITY_views InventoryList.vue, alertAction()]]
- 1 edge to [[_COMMUNITY_views Menu.vue, activeCategory]]

## Top bridge nodes
- [[api.js]] - degree 16, connects to 9 communities
- [[AuditLogs.vue]] - degree 28, connects to 2 communities
- [[RestoreBackupModal.vue]] - degree 12, connects to 1 community
- [[fetchLogs()]] - degree 5, connects to 1 community
- [[fetchUsers()_1]] - degree 2, connects to 1 community