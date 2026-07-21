# Análisis de Módulos y Plan de Desarrollo - Ohana Acai V3

El sistema actual está diseñado como un **POS Offline-First** con una arquitectura SPA/PWA en Vue.js (frontend) y una API RESTful en Laravel 13 (backend). 

## 🛣️ Ruta de Desarrollo Sugerida (Por Dependencias)

Para trabajar de manera ordenada y evitar bloqueos técnicos, te sugiero esta hoja de ruta. Está diseñada de manera que cada módulo construido sirva como base (dependencia) para el siguiente:

### Fase 1: Cimientos y Seguridad
**Módulo:** Autenticación y Gestión de Usuarios (y Auditoría)
* **Por qué aquí:** Todo el sistema requiere saber quién realiza una acción para la seguridad y los `AuditLogs`. Sin esto configurado, probar el resto de los módulos será difícil.
* **Qué incluye:** Login, recuperación de contraseñas, roles, permisos y visualización de logs de auditoría.

### Fase 2: Catálogo Base (Lo que se vende)
**Módulo:** Catálogo y Productos
* **Por qué aquí:** No se puede vender ni armar recetas sin antes tener un catálogo estructurado.
* **Qué incluye:** `Category`, `OptionGroup`, `Option`, `Product` y `ProductVariant`. 
* **Frontend:** Vistas administrativas para gestionar el menú y la descarga inicial (Offline-First) en `catalog.js`.

### Fase 3: Gestión de Materia Prima (Lo que se consume)
**Módulo:** Inventario Básico
* **Por qué aquí:** Para costear y manejar el inventario de lo que se vende, primero hay que dar de alta los ingredientes crudos.
* **Qué incluye:** `IngredientCategory`, `Ingredient` e `InventoryMovement` (Ingresos, mermas y ajustes de stock manuales).
* **Frontend:** La vista `InventoryList.vue`.

### Fase 4: El Puente (Recetas)
**Módulo:** Recetas (`Recipe` y `OptionRecipe`)
* **Por qué aquí:** Une la Fase 2 (Productos) con la Fase 3 (Ingredientes).
* **Qué incluye:** Configurar cuánto de cada ingrediente consume la venta de un producto específico o la elección de una opción/extra. Esto es crucial para la rebaja automática de inventario.

### Fase 5: Operación y Cobro
**Módulo:** Punto de Venta (POS) y Ventas
* **Por qué aquí:** Depende directamente del catálogo (Fase 2) y detona el consumo de recetas e inventario (Fases 3 y 4).
* **Qué incluye:** `CashRegisterSession` (Apertura/Cierre de turnos), `Sale`, `SaleItem`, pagos y sincronización offline (el core del sistema).
* **Frontend:** Vistas `Pos.vue` y `Turno.vue`. Sincronización de transacciones mediante UUIDs.

### Fase 6: Expansión Comercial
**Módulos:** Clientes, Fidelización (Loyalty) y Delivery
* **Por qué aquí:** Son satélites de la venta. Se pueden integrar una vez que el flujo básico de cobrar en caja esté funcionando perfectamente.
* **Qué incluye:** `Customer`, `LoyaltyConfig`, y `DeliveryOrder`.

---

## 📦 Detalle Técnico de los Módulos Identificados

* **Autenticación:** Modelos (`User`, `RolePermission`). Vistas (`Login.vue`, `AdminUsers.vue`).
* **Catálogo:** Modelos (`Category`, `Product`, `ProductVariant`, `OptionGroup`, `Option`).
* **Inventario/Recetas:** Modelos (`Ingredient`, `IngredientCategory`, `InventoryMovement`, `Recipe`, `OptionRecipe`).
* **Ventas (POS):** Modelos (`CashRegisterSession`, `Sale`, `SaleItem`, `SaleItemOption`, `SalePayment`). Vistas (`Pos.vue`, `Turno.vue`).
* **Fidelización:** Modelos (`Customer`, `LoyaltyConfig`).
* **Delivery:** Modelos (`DeliveryOrder`). Vistas (`Delivery.vue`).
* **Logs:** Modelos (`AuditLog`). Vistas (`AuditLogs.vue`).

---

> [!IMPORTANT]
> ## 📝 Aprobación del Plan
> 
> Esta hoja de ruta organiza el trabajo lógicamente. Si estás de acuerdo, **el siguiente paso sería comenzar con la Fase 1 (Cimientos y Seguridad)**, asegurándonos que el frontend y el backend de usuarios/roles estén al 100%.
> 
> ¿Apruebas este enfoque para comenzar a trabajar en la **Fase 1**?
