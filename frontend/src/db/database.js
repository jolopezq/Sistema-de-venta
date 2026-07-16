import Dexie from 'dexie';

export const db = new Dexie('OhanaAcaiDB');

// Definición de esquema
// Sólo indexamos los campos por los que vamos a buscar/filtrar frecuentemente.
db.version(2).stores({
  categories: 'id, name, sort_order',
  products: 'id, category_id, name',
  customers: 'id, ci_or_phone, name',
  sales: 'id, sync_status, created_at' // id es UUIDv4
});
