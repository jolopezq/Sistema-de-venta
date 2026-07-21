import Dexie from 'dexie';

export const db = new Dexie('OhanaAcaiDB');

/**
 * Definición del esquema IndexedDB — Offline-First (Dexie.js)
 *
 * REGLA: Solo se indexan los campos por los que se hace búsqueda/filtrado.
 * Los objetos anidados como `option_groups[].options[]` NO se indexan;
 * Dexie los serializa automáticamente como JSON al guardarse con bulkPut/bulkAdd.
 *
 * v4: Se agrega índice `is_active` en productos para poder filtrar
 *     solo los disponibles en el POS sin cargar todos en memoria.
 */
db.version(5).stores({
  categories: 'id, name, sort_order',
  // is_active permite filtrar solo productos disponibles en el POS
  // option_groups (anidado) se guarda como JSON automáticamente
  products:   'id, category_id, name, is_active',
  ingredients: 'id, name', // Agregado para validación de stock offline
  customers:  'id, ci_or_phone, name',
  sales:      'id, sync_status, created_at', // id es UUIDv4
  users:      'id, email',
  role_permissions: 'id, role, [role+module]'
});
