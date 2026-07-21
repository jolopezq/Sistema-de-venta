# Tareas - Fase 1: Cimientos y Seguridad

- `[x]` **Backend: Controladores y Validaciones**
  - `[x]` Revisar y asegurar validaciones estrictas (FormRequests) en `AuthController`, `UserController` y `RolePermissionController`.
  - `[x]` Asegurar que el soft delete en `UserController@destroy` funcione correctamente y genere log de auditoría.
  - `[x]` Verificar el reset de contraseña (`UserController@resetPassword`).
  
- `[x]` **Backend: Logs de Auditoría**
  - `[x]` Implementar Traits o Listeners en el modelo `User` y `RolePermission` para que los cambios queden registrados automáticamente en `AuditLogs`.
  - `[x]` Asegurar el endpoint GET `/audit-logs`.

- `[x]` **Frontend: Vista de Login**
  - `[x]` Probar el login online y offline interactuando con `IndexedDB`.
  - `[x]` Agregar retroalimentación visual amigable (spinners, alertas de credenciales incorrectas).

- `[x]` **Frontend: Vista de Admin (Permisos)**
  - `[x]` Crear/Mejorar el panel de gestión de roles en `AdminUsers.vue`.
  - `[x]` Integrar con los endpoints de `RolePermissionController` para modificar permisos en vivo.
  - `[x]` Sincronizar roles con `Pinia` y la interfaz para que botones sensibles se inhabiliten sin permiso.

- `[x]` **Frontend: Vista de Auditoría (Super Admin)**
  - `[x]` Crear una tabla que consuma `/audit-logs` (usando `AuditLogs.vue`).
  - `[x]` Implementar una función para parsear `old_values` y `new_values` de JSON a formato legible. (valores antiguos vs nuevos).

- `[ ]` **Pruebas y Verificación**
  - `[ ]` Ejecutar las migraciones y seeders para generar un super_admin de prueba.
  - `[ ]` Verificar que un cajero no puede entrar a rutas bloqueadas en el frontend basándose en sus permisos de `auth.js`.
