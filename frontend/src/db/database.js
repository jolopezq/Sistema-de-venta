import Dexie from 'dexie';

export const db = new Dexie('OhanaAcaiDB');

// Definición de esquema
// Sólo indexamos los campos por los que vamos a buscar/filtrar frecuentemente.
db.version(1).stores({
  categories: 'id, parent_id, sort_order',
  products: 'id, category_id, is_active, name',
  customers: 'id, ci_or_phone, name',
  sales: 'id, sync_status, created_at' // id es UUIDv4
});
