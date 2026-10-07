---
source_file: "backend/composer.json"
type: "code"
community: "backend: post-root-package-install, setup"
location: "L36"
tags:
  - graphify/code
  - graphify/EXTRACTED
  - community/backend_post-root-package-install_setup
---

# setup

## Connections
- [[@php -r file_exists('.env')  copy('.env.example', '.env');]] - `extends` [EXTRACTED]
- [[@php artisan keygenerate]] - `extends` [EXTRACTED]
- [[@php artisan migrate --force]] - `extends` [EXTRACTED]
- [[composer install]] - `extends` [EXTRACTED]
- [[npm install --ignore-scripts]] - `extends` [EXTRACTED]
- [[npm run build]] - `extends` [EXTRACTED]
- [[scripts]] - `contains` [EXTRACTED]

#graphify/code #graphify/EXTRACTED #community/backend_post-root-package-install_setup