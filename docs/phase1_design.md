# Diseño de Fase 1: Cimientos y Seguridad

Este documento describe la arquitectura, flujos y diseño de interfaz para el módulo de Autenticación, Gestión de Usuarios, Roles/Permisos y Auditoría. Se basa en el código ya definido en Laravel 13 y Vue.js.

## 🗄️ Esquema de Base de Datos (ERD)

```mermaid
erDiagram
    USERS {
        bigint id PK
        string name
        string email
        string password
        string role "ENUM('super_admin', 'admin', 'cashier')"
        string ci
        string phone
        date start_date
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "Soft Deletes"
    }

    ROLE_PERMISSIONS {
        bigint id PK
        string role
        string module
        string access_level "ENUM('none', 'read', 'edit')"
        timestamp created_at
        timestamp updated_at
    }

    AUDIT_LOGS {
        bigint id PK
        bigint user_id FK
        string action
        string entity_type
        bigint entity_id
        json old_values
        json new_values
        string ip_address
        timestamp created_at
    }

    USERS ||--o{ AUDIT_LOGS : "genera"
```

## 🔄 Flujos de Sistema (Sequence Diagrams)

### 1. Flujo de Login (Híbrido: Online / Offline)

El sistema de caja está diseñado para funcionar aunque no haya internet, almacenando un hash de la contraseña en IndexedDB.

```mermaid
sequenceDiagram
    actor Cajero
    participant UI as Vue (Login.vue)
    participant Store as auth.js (Pinia)
    participant IDB as IndexedDB (Dexie)
    participant API as Laravel (API)

    Cajero->>UI: Ingresa Email y Password
    UI->>Store: login(email, password)
    
    alt Hay Conexión a Internet
        Store->>API: POST /api/login
        API-->>Store: 200 OK (User, Token, Permissions)
        Store->>IDB: Guarda/Actualiza User (con hash de pass offline) y Permisos
        Store->>UI: Redirige a POS
    else Sin Conexión (Offline)
        Store->>IDB: Busca User por Email
        IDB-->>Store: User + hash_offline
        Store->>Store: Genera SHA-256 local del password ingresado
        alt Hashes coinciden
            Store->>Store: Setea token 'offline-token'
            Store->>UI: Redirige a POS en modo Offline
        else Hashes NO coinciden
            Store-->>UI: Error: Credenciales incorrectas
        end
    end
```

### 2. Flujo de Matriz de Permisos (RBAC)

Los permisos se asignan a roles completos, no a usuarios individuales, para facilitar la administración.

```mermaid
sequenceDiagram
    actor SuperAdmin
    participant UI as AdminUsers.vue
    participant API as RolePermissionController
    participant DB as MySQL

    SuperAdmin->>UI: Modifica matriz de permisos
    UI->>API: PUT /api/permissions {permissions: [...]}
    API->>API: Valida payload y permisos del usuario actual
    API->>DB: Actualiza registros (upsert)
    API->>DB: Crea registro en AuditLogs
    API-->>UI: 200 OK
    UI->>SuperAdmin: Muestra Toast "Permisos actualizados"
```

## 🎨 Interfaz de Usuario (UI)

Las interfaces ya desarrolladas en el frontend siguen un sistema de diseño premium con:
- **Colores principales:** Açaí (Morado `#4B2E52`), Leaf (Verde `#5C7A3F`), Coffee (`#8B5E3C`).
- **Tipografía:** Fraunces para títulos, Manrope para interfaces, JetBrains Mono para datos precisos (CI, IDs).
- **Componentes Modales y Toasts** para feedback inmediato.

### `Login.vue`
- Layout centrado.
- Advertencia visual si el sistema detecta que está en modo Offline (banner rojo/naranja).
- Inputs de email y contraseña (o PIN).

### `AdminUsers.vue`
- **Tabs:** Dos pestañas principales (Usuarios, Roles y Permisos).
- **Tab Usuarios:** Tabla con lista de usuarios (Avatar, Nombre, CI, Rol con color de badge). Botones de acción: Editar, Resetear Password, Eliminar.
- **Tab Permisos:** Una matriz interactiva. Filas = Módulos, Columnas = Roles (Cajero, Admin). Botones segmentados interactivos para seleccionar `Ninguno`, `Lectura`, `Edición`.
- **Modales:** Modal estilizado para la creación/edición de usuarios.

---

> [!NOTE]
> Revisa la lista de tareas de implementación en el archivo `task.md` y confirma si apruebas este diseño para empezar a ejecutar el código (backend y frontend) faltante.
