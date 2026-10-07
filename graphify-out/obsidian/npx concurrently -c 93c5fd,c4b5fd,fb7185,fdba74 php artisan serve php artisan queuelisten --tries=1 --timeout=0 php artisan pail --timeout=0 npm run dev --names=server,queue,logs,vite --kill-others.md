---
source_file: "backend/composer.json"
type: "concept"
community: "backend: scripts, dev"
location: "L44"
tags:
  - graphify/concept
  - graphify/EXTRACTED
  - community/backend_scripts_dev
---

# npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74\" \"php artisan serve\" \"php artisan queue:listen --tries=1 --timeout=0\" \"php artisan pail --timeout=0\" \"npm run dev\" --names=server,queue,logs,vite --kill-others

## Connections
- [[dev]] - `extends` [EXTRACTED]

#graphify/concept #graphify/EXTRACTED #community/backend_scripts_dev