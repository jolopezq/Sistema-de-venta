# Ohana Acai V3 - Sistema POS y Gestión

Sistema de Punto de Venta (POS) y gestión diseñado con un enfoque principal en **Offline-First**. Garantiza alta disponibilidad y rapidez en el punto de venta, permitiendo cobrar y operar aunque se pierda la conexión a internet temporalmente.

## 🚀 Arquitectura y Tecnologías

El sistema está dividido en dos capas principales que interactúan mediante una API RESTful, diseñadas bajo estrictos estándares de *PHP The Right Way* en el backend y PWA/SPA en el frontend.

### Frontend (SPA / PWA)
- **Framework:** Vue.js (v3)
- **Gestión de Estado:** Pinia (carrito de compras, sesión, estado de red)
- **Base de Datos Local:** IndexedDB usando **Dexie.js** para almacenamiento temporal de catálogo, configuración y funcionamiento en modo offline.
- **Construcción:** Vite
- **Service Workers:** Google Workbox (estrategias Cache First / Stale-While-Revalidate) para assets estáticos.

### Backend (RESTful API)
- **Framework:** Laravel (v13.x) con PHP 8.3
- **Base de Datos Principal:** MySQL / MariaDB (utf8mb4) como fuente de verdad.
- **Autenticación:** Laravel Sanctum (Tokens stateful/stateless).
- **Comunicación y Sincronización:**
  - Uso de **Jobs y Colas** para la ingesta masiva de transacciones en modo offline.
  - Generación de **UUIDs versión 4** desde el cliente (Frontend) para las transacciones para evitar conflictos de IDs offline.
  - Respuestas estandarizadas centralizadas a través de un `ExceptionHandler`.

## 📦 Estructura del Proyecto

El repositorio está organizado en un monorepo lógico:

```
.
├── backend/            # API RESTful en Laravel 13
│   ├── app/            # Controladores, Modelos, Repositorios, Jobs
│   ├── routes/         # Rutas de la API (api.php)
│   └── ...             
└── frontend/           # Aplicación Web y POS en Vue.js / Vite
    ├── src/            
    │   ├── components/ # Componentes reutilizables
    │   ├── db/         # Configuración Dexie.js (Base de datos Local)
    │   ├── views/      # Páginas y vistas (Caja, Login, Configuración)
    │   └── ...         
    └── ...             
```

## 🛠 Instalación y Configuración Local

### Prerrequisitos
- **PHP** >= 8.3
- **Composer** (para backend)
- **Node.js** >= 18 y npm (para frontend)
- **MySQL/MariaDB**

### Configuración del Backend (Laravel)

1. Ingresa al directorio del backend:
   ```bash
   cd backend
   ```
2. Instala las dependencias de PHP:
   ```bash
   composer install
   ```
3. Configura las variables de entorno:
   ```bash
   cp .env.example .env
   ```
   *Edita `.env` con tus credenciales de base de datos.*
4. Genera la llave de la aplicación y ejecuta las migraciones:
   ```bash
   php artisan key:generate
   php artisan migrate
   ```
5. Levanta el servidor local:
   ```bash
   php artisan serve
   ```
   *Nota: Recuerda mantener el worker de colas activo (`php artisan queue:listen`) si estás probando la sincronización.*

### Configuración del Frontend (Vue.js)

1. Ingresa al directorio del frontend:
   ```bash
   cd frontend
   ```
2. Instala las dependencias de JavaScript:
   ```bash
   npm install
   ```
3. Levanta el servidor de desarrollo:
   ```bash
   npm run dev
   ```

## 🧩 Patrones y Estándares Críticos

Este proyecto sigue una guía de buenas prácticas obligatoria (`buenas-practicas.md`):

1. **Idempotencia de Red:** Cada venta viaja desde el cliente al backend con un UUID v4. Si una transacción ya existe, el backend devuelve un 200 OK y no duplica el cobro.
2. **Sincronización Selectiva:** Solo se descarga localmente a IndexedDB el catálogo activo y listas de precios (no el historial histórico).
3. **Inyección de Dependencias:** Obligatorio inyectar clases mediante constructores (Service Container), sin usar instanciaciones directas manuales (`new Class`).
4. **Repositorios y Transaccionalidad:** Uso del Patrón Repositorio. Lógica crítica y consultas complejas (cálculo de CPP, rebaja de inventarios) deben estar aisladas y ejecutarse bajo `DB::transaction()`.
5. **Soft Deletes (Borrado Lógico):** Prohibido el `DELETE` físico directo en datos transaccionales, de usuarios o inventario.

---
*Desarrollado para la operación ágil y robusta de Ohana Acai.*
