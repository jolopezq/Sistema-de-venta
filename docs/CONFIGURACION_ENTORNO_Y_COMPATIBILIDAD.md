# 💻 Entorno de Producción Windows y Guía de Compatibilidad Mac 🍏

Este documento describe la configuración técnica de la **portátil de producción (Servidor Windows)** del sistema **Ohana Açaí POS** y establece los lineamientos obligatorios de compatibilidad para cuando se desarrollen y suban cambios desde una **Mac** (o cualquier otro equipo).

---

## 1. Ficha Técnica de la Portátil de Producción (Windows)

| Componente | Especificación / Versión | Ubicación / Detalle |
| :--- | :--- | :--- |
| **Sistema Operativo** | Microsoft Windows (x64) | `C:\Sistema de Ventas\` |
| **PHP** | `v8.4.25` (x64 NTS) | En `PATH` o `C:\Ohana-POS-Windows\php\php.exe` |
| **Node.js** | `v24.19.0` | Node.js Runtime |
| **NPM** | `v11.17.0` | Gestor de paquetes frontend |
| **Git** | `v2.55+` para Windows | Rama activa: `main` |
| **Motor de Base de Datos** | **SQLite 3** | `backend/database/database.sqlite` |
| **Backend Framework** | Laravel 11.x + Laravel Sanctum | `backend/` |
| **Frontend Framework** | Vue.js 3 + Pinia + Dexie.js + Vite 5 | `frontend/` |
| **Servidor de Producción** | `php artisan serve` | Puerto `8000` (`http://0.0.0.0:8000`) |
| **Túnel de Acceso Remoto** | Cloudflare Tunnel (`cloudflared`) | `https://www.pos.ohana.com` |
| **Red LAN de Casa** | Subred `192.168.1.0/24` | IP Servidor: `192.168.1.9` |

---

## 2. Flujo de Actualización Automática en la Portátil

Cuando se suben cambios desde la Mac a la rama `main` en GitHub (`git push origin main`):

1. **El Vigilante Automático** (`vigilante-actualizaciones.ps1`) o el script manual (`actualizar-pos.bat`) detectan los nuevos commits.
2. Realizan un **respaldo preventivo automático** de la base de datos SQLite en `backups/`.
3. Descargan los cambios mediante `git pull origin main`.
4. Si detecta cambios en `backend/database/migrations/`: ejecuta `php artisan migrate --force`.
5. Si detecta cambios en `backend/`: ejecuta `php artisan optimize:clear`.
6. Si detecta cambios en `frontend/`: ejecuta `npm run build` y publica los archivos en `backend/public/`.
7. Reinicia el servidor en el puerto 8000 sin intervención manual.

---

## 3. ⚠️ Reglas Obligatorias de Compatibilidad (Desarrollo desde Mac)

Para garantizar que cualquier cambio enviado desde la Mac se aplique y funcione a la perfección en la portátil Windows, sigue estas reglas:

### 🗄️ A. Base de Datos y Migraciones
* **Nunca commitear el archivo SQLite:** `backend/database/database.sqlite` está ignorado en `.gitignore`. La portátil contiene los datos reales de ventas y catálogo.
* **Todo cambio de esquema DEBE ser una migración:** Si agregas una columna, tabla o índice, crea una migración con `php artisan make:migration ...`. El actualizador de Windows la aplicará automáticamente con `--force`.
* **Compatibilidad SQLite:** Recuerda que el servidor corre en **SQLite**. Evita sintaxis o tipos de datos exclusivos de MySQL que SQLite no soporte en migraciones (ej: cláusulas `AFTER column`, llaves foráneas complejas con alter table sin drop, etc.).

### 📁 B. Manejo de Rutas de Archivos (Path Handling)
* **Prohibido usar rutas absolutas de Mac (`/Users/...`):** Nunca hardcodear rutas absolutas de tu máquina en el código.
* **Separadores de Directorio:** Usa siempre los helpers de Laravel:
  * `base_path('...')`
  * `database_path('...')`
  * `storage_path('...')`
  * `public_path('...')`
* En PHP, usa `/` (compatible universalmente) o `DIRECTORY_SEPARATOR`.

### 🌐 C. Rutas API y Autenticación (Sanctum)
* **Nombres de Ruta y Respuestas JSON:** Toda ruta protegida debe retornar respuestas JSON estandarizadas ante fallos.
* **Ruta de Login:** La ruta de autenticación en `backend/routes/api.php` debe conservar su nombre `->name('login')` para evitar excepciones `RouteNotFoundException`.
* **CORS y Dominios Permitidos:** En `backend/.env` y `config/cors.php`, mantén siempre permitidos:
  * `localhost`, `127.0.0.1`, `127.0.0.1:8000`
  * `192.168.1.9`, `192.168.1.9:8000`
  * `https://www.pos.ohana.com`, `https://pos.ohana.com`

### 📦 D. Dependencias y Paquetes (Composer y NPM)
* **Nuevas dependencias de Backend:** Si instalas un paquete en la Mac, commitea tanto `backend/composer.json` como `backend/composer.lock`.
* **Nuevas dependencias de Frontend:** Si agregas librerías Vue/JS, commitea tanto `frontend/package.json` como `frontend/package-lock.json`.
* **No commitear `dist/` ni `node_modules/`:** La portátil compila automáticamente su propio bundle de producción con `npm run build`.

### 🔤 E. Codificación y Fin de Líneas (Line Endings)
* Guarda todos los archivos en **UTF-8 sin BOM**.
* Configura Git en la Mac para manejar saltos de línea de forma neutral:
  ```bash
  git config --global core.autocrlf input
  ```

---

## 4. Checklist Rápido antes de hacer `git push` desde la Mac

- [ ] ¿Los cambios de base de datos tienen su archivo de migración en `backend/database/migrations`?
- [ ] ¿Probaste que el frontend compila localmente con `npm run build` sin errores de Vite?
- [ ] ¿Verificaste que no hay rutas absolutas locales tipo `/Users/jolopez/...`?
- [ ] ¿El archivo `.env` o `database.sqlite` quedaron fuera del commit?
- [ ] ¿Subiste a la rama `main` (`git push origin main`)?

---

*Documentación generada para Ohana Açaí POS V3.*
