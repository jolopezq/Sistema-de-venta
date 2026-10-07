---
type: community
cohesion: 0.50
members: 4
---

# backend: post-create-project-cmd, @php artisa

**Cohesion:** 0.50 - moderately connected
**Members:** 4 nodes

## Members
- [[@php -r file_exists('databasedatabase.sqlite')  touch('databasedatabase.sqlite');]] - concept - backend/composer.json
- [[@php artisan keygenerate --ansi]] - concept - backend/composer.json
- [[@php artisan migrate --graceful --ansi]] - concept - backend/composer.json
- [[post-create-project-cmd]] - code - backend/composer.json

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/backend_post-create-project-cmd_php_artisa
SORT file.name ASC
```

## Connections to other communities
- 1 edge to [[_COMMUNITY_backend scripts, dev]]

## Top bridge nodes
- [[post-create-project-cmd]] - degree 4, connects to 1 community