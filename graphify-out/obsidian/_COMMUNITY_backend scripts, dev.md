---
type: community
cohesion: 0.14
members: 14
---

# backend: scripts, dev

**Cohesion:** 0.14 - loosely connected
**Members:** 14 nodes

## Members
- [[@php artisan configclear --ansi @no_additional_args]] - concept - backend/composer.json
- [[@php artisan packagediscover --ansi]] - concept - backend/composer.json
- [[@php artisan test]] - concept - backend/composer.json
- [[@php artisan vendorpublish --tag=laravel-assets --ansi --force]] - concept - backend/composer.json
- [[ComposerConfigdisableProcessTimeout]] - concept - backend/composer.json
- [[IlluminateFoundationComposerScriptspostAutoloadDump]] - concept - backend/composer.json
- [[IlluminateFoundationComposerScriptsprePackageUninstall]] - concept - backend/composer.json
- [[dev]] - code - backend/composer.json
- [[npx concurrently -c 93c5fd,c4b5fd,fb7185,fdba74 php artisan serve php artisan queuelisten --tries=1 --timeout=0 php artisan pail --timeout=0 npm run dev --names=server,queue,logs,vite --kill-others]] - concept - backend/composer.json
- [[post-autoload-dump]] - code - backend/composer.json
- [[post-update-cmd]] - code - backend/composer.json
- [[pre-package-uninstall]] - code - backend/composer.json
- [[scripts]] - code - backend/composer.json
- [[test]] - code - backend/composer.json

## Live Query (requires Dataview plugin)

```dataview
TABLE source_file, type FROM #community/backend_scripts_dev
SORT file.name ASC
```

## Connections to other communities
- 2 edges to [[_COMMUNITY_backend post-root-package-install, setup]]
- 1 edge to [[_COMMUNITY_backend composer.json, autoload-dev]]
- 1 edge to [[_COMMUNITY_backend post-create-project-cmd, @php artisa]]

## Top bridge nodes
- [[scripts]] - degree 9, connects to 3 communities