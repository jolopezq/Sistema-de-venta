---
type: community
cohesion: 0.25
members: 8
---

# backend: post-root-package-install, setup

**Cohesion:** 0.25 - loosely connected
**Members:** 8 nodes

## Members
- [[@php -r file_exists('.env')  copy('.env.example', '.env');]] - concept - backend/composer.json
- [[@php artisan keygenerate]] - concept - backend/composer.json
- [[@php artisan migrate --force]] - concept - backend/composer.json
- [[composer install]] - concept - backend/composer.json
- [[npm install --ignore-scripts]] - concept - backend/composer.json
- [[npm run build]] - concept - backend/composer.json
- [[post-root-package-install]] - code - backend/composer.json
- [[setup]] - code - backend/composer.json

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/backend_post-root-package-install_setup
SORT file.name ASC
```

## Connections to other communities
- 2 edges to [[_COMMUNITY_backend scripts, dev]]

## Top bridge nodes
- [[setup]] - degree 7, connects to 1 community
- [[post-root-package-install]] - degree 2, connects to 1 community